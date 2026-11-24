//go:build windows

package main

import (
	"encoding/xml"
	"errors"
	"fmt"
	"strings"
	"syscall"
	"time"
	"unsafe"

	"golang.org/x/sys/windows"
)

// Windows-logboeken lezen met de Event Log-API (wevtapi.dll): per logboek de gebeurtenissen na een bladwijzer
// (EventRecordID), met de tekst zoals Logboeken die toont (EvtFormatMessage).

var (
	wevtapi            = windows.NewLazySystemDLL("wevtapi.dll")
	procEvtQuery       = wevtapi.NewProc("EvtQuery")
	procEvtNext        = wevtapi.NewProc("EvtNext")
	procEvtRender      = wevtapi.NewProc("EvtRender")
	procEvtClose       = wevtapi.NewProc("EvtClose")
	procEvtOpenPubMeta = wevtapi.NewProc("EvtOpenPublisherMetadata")
	procEvtFormatMsg   = wevtapi.NewProc("EvtFormatMessage")
	procEvtOpenLog     = wevtapi.NewProc("EvtOpenLog")
	procEvtGetLogInfo  = wevtapi.NewProc("EvtGetLogInfo")
)

const (
	evtQueryChannelPath      = 0x1
	evtQueryForwardDirection = 0x100
	evtQueryReverseDirection = 0x200
	evtRenderEventXML        = 1
	evtFormatMessageEvent    = 1
	evtOpenChannelPath       = 1
	evtLogNumberOfLogRecords = 5
	errNoMoreItems           = syscall.Errno(259)
	errInsufficientBuffer    = syscall.Errno(122)
)

type evtXML struct {
	System struct {
		Provider struct {
			Name string `xml:"Name,attr"`
		} `xml:"Provider"`
		EventID     int `xml:"EventID"`
		Level       int `xml:"Level"`
		TimeCreated struct {
			SystemTime string `xml:"SystemTime,attr"`
		} `xml:"TimeCreated"`
		EventRecordID uint64 `xml:"EventRecordID"`
	} `xml:"System"`
	EventData struct {
		Data []struct {
			Name  string `xml:"Name,attr"`
			Value string `xml:",chardata"`
		} `xml:"Data"`
	} `xml:"EventData"`
}

func evtClose(h uintptr) {
	if h != 0 {
		procEvtClose.Call(h)
	}
}

func render(h uintptr) (string, error) {
	var used, props uint32
	procEvtRender.Call(0, h, evtRenderEventXML, 0, 0, uintptr(unsafe.Pointer(&used)), uintptr(unsafe.Pointer(&props)))
	if used == 0 {
		return "", errors.New("leeg")
	}
	buf := make([]uint16, used/2+1)
	r, _, err := procEvtRender.Call(0, h, evtRenderEventXML, uintptr(len(buf)*2), uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&used)), uintptr(unsafe.Pointer(&props)))
	if r == 0 {
		return "", err
	}
	return windows.UTF16ToString(buf), nil
}

// evtReader: houdt de "publisher metadata" vast (nodig voor de tekst van een gebeurtenis) zolang hij leest
type evtReader struct{ pubs map[string]uintptr }

func newEvtReader() *evtReader { return &evtReader{pubs: map[string]uintptr{}} }

func (r *evtReader) close() {
	for _, h := range r.pubs {
		evtClose(h)
	}
}

func (r *evtReader) message(provider string, h uintptr) string {
	pm, ok := r.pubs[provider]
	if !ok {
		p, _ := windows.UTF16PtrFromString(provider)
		pm, _, _ = procEvtOpenPubMeta.Call(0, uintptr(unsafe.Pointer(p)), 0, 0, 0)
		r.pubs[provider] = pm
	}
	if pm == 0 {
		return ""
	}
	var used uint32
	procEvtFormatMsg.Call(pm, h, 0, 0, 0, evtFormatMessageEvent, 0, 0, uintptr(unsafe.Pointer(&used)))
	if used == 0 || used > 1<<20 {
		return ""
	}
	buf := make([]uint16, used+1)
	if rr, _, _ := procEvtFormatMsg.Call(pm, h, 0, 0, 0, evtFormatMessageEvent, uintptr(len(buf)), uintptr(unsafe.Pointer(&buf[0])), uintptr(unsafe.Pointer(&used))); rr == 0 {
		return ""
	}
	return strings.TrimSpace(windows.UTF16ToString(buf))
}

// read: gebeurtenissen uit een logboek. query = XPath voor System (leeg = alles) zonder de bladwijzer; since = laatste
// EventRecordID die al gelezen is (0 = alleen vanaf nu: de nieuwste wordt de bladwijzer). Hooguit max per keer.
func (r *evtReader) read(channel, filter string, since uint64, max int, withMsg bool) ([]winEvent, uint64, error) {
	if since == 0 {
		last, err := lastRecordID(channel)
		return nil, last, err
	}
	cond := fmt.Sprintf("EventRecordID > %d", since)
	if filter != "" {
		cond = "(" + filter + ") and " + cond
	}
	q := "*[System[" + cond + "]]"
	cp, _ := windows.UTF16PtrFromString(channel)
	qp, _ := windows.UTF16PtrFromString(q)
	rs, _, err := procEvtQuery.Call(0, uintptr(unsafe.Pointer(cp)), uintptr(unsafe.Pointer(qp)), evtQueryChannelPath|evtQueryForwardDirection)
	if rs == 0 {
		return nil, since, fmt.Errorf("logboek %s: %v", channel, err)
	}
	defer evtClose(rs)
	var out []winEvent
	last := since
	hs := make([]uintptr, 64)
	for len(out) < max {
		var n uint32
		ok, _, err := procEvtNext.Call(rs, uintptr(len(hs)), uintptr(unsafe.Pointer(&hs[0])), 2000, 0, uintptr(unsafe.Pointer(&n)))
		if ok == 0 {
			if errors.Is(err, errNoMoreItems) {
				break
			}
			return out, last, err
		}
		for i := 0; i < int(n); i++ {
			h := hs[i]
			if s, err := render(h); err == nil {
				var x evtXML
				if xml.Unmarshal([]byte(s), &x) == nil {
					e := winEvent{RecordID: x.System.EventRecordID, Level: x.System.Level, ID: x.System.EventID, Provider: x.System.Provider.Name, Channel: channel, Data: map[string]string{}}
					e.Time, _ = time.Parse(time.RFC3339Nano, x.System.TimeCreated.SystemTime)
					for _, d := range x.EventData.Data {
						if d.Name != "" {
							e.Data[d.Name] = strings.TrimSpace(d.Value)
						} else if e.Data["_"] == "" {
							e.Data["_"] = strings.TrimSpace(d.Value)
						}
					}
					if withMsg {
						e.Message = r.message(e.Provider, h)
					}
					out = append(out, e)
					if e.RecordID > last {
						last = e.RecordID
					}
				}
			}
			evtClose(h)
		}
	}
	return out, last, nil
}

// lastRecordID: de nieuwste EventRecordID van een logboek (0 als het leeg is)
func lastRecordID(channel string) (uint64, error) {
	cp, _ := windows.UTF16PtrFromString(channel)
	qp, _ := windows.UTF16PtrFromString("*")
	rs, _, err := procEvtQuery.Call(0, uintptr(unsafe.Pointer(cp)), uintptr(unsafe.Pointer(qp)), evtQueryChannelPath|evtQueryReverseDirection)
	if rs == 0 {
		return 0, fmt.Errorf("logboek %s: %v", channel, err)
	}
	defer evtClose(rs)
	var h uintptr
	var n uint32
	if ok, _, _ := procEvtNext.Call(rs, 1, uintptr(unsafe.Pointer(&h)), 2000, 0, uintptr(unsafe.Pointer(&n))); ok == 0 || n == 0 {
		return 0, nil
	}
	defer evtClose(h)
	s, err := render(h)
	if err != nil {
		return 0, err
	}
	var x evtXML
	if err := xml.Unmarshal([]byte(s), &x); err != nil {
		return 0, err
	}
	return x.System.EventRecordID, nil
}

// channelRecords: bestaat het logboek, en hoeveel gebeurtenissen staan erin?
func channelRecords(channel string) (uint64, bool) {
	cp, _ := windows.UTF16PtrFromString(channel)
	h, _, _ := procEvtOpenLog.Call(0, uintptr(unsafe.Pointer(cp)), evtOpenChannelPath)
	if h == 0 {
		return 0, false
	}
	defer evtClose(h)
	var v [16]byte // EVT_VARIANT: waarde (8), aantal (4), soort (4)
	var used uint32
	if ok, _, _ := procEvtGetLogInfo.Call(h, evtLogNumberOfLogRecords, uintptr(len(v)), uintptr(unsafe.Pointer(&v[0])), uintptr(unsafe.Pointer(&used))); ok == 0 {
		return 0, true
	}
	return *(*uint64)(unsafe.Pointer(&v[0])), true
}
