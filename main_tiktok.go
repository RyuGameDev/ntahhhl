package main

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"math/rand"
	"net/http"
	"net/url"
	"os"
	"regexp"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

const (
	HOST        = "api19.tiktokv.com"
	REFRESHRATE = 250 * time.Millisecond
)

var (
	BUILDS = []int{247, 312, 322, 357, 358, 415, 422, 444, 466}
	vrgx   = regexp.MustCompile(`(?:/video/|/photo/|v=|/v/|\?app=video&id=)(\d{10,30})`)
)

type DeviceModel struct {
	Model string
	Brand string
	Res   string
	DPI   string
}

var androidModels = []DeviceModel{
	{"SM-F926B", "samsung", "1080x2400", "480"},
	{"SM-G998B", "samsung", "1440x3200", "560"},
	{"SM-G991B", "samsung", "1080x2400", "420"},
	{"SM-A536B", "samsung", "1080x2400", "400"},
	{"SM-A546B", "samsung", "1080x2400", "400"},
	{"SM-S918B", "samsung", "1440x3120", "550"},
	{"SM-G781B", "samsung", "1080x2400", "420"},
	{"Pixel 7", "google", "1080x2400", "440"},
	{"Pixel 6", "google", "1080x2400", "440"},
	{"Pixel 8", "google", "1080x2400", "480"},
	{"Pixel 8 Pro", "google", "1440x3120", "550"},
	{"Pixel 7a", "google", "1080x2400", "440"},
	{"Redmi Note 12", "xiaomi", "1080x2400", "440"},
	{"Redmi Note 11", "xiaomi", "1080x2400", "400"},
	{"Redmi Note 13", "xiaomi", "1080x2400", "440"},
	{"POCO F5", "xiaomi", "1080x2400", "440"},
	{"POCO F6", "xiaomi", "1080x2400", "440"},
	{"POCO X5", "xiaomi", "1080x2400", "440"},
	{"OnePlus 11", "oneplus", "1440x3216", "525"},
	{"OnePlus 10", "oneplus", "1440x3216", "525"},
	{"OnePlus 12", "oneplus", "1440x3216", "525"},
	{"OnePlus 9", "oneplus", "1440x3216", "525"},
	{"OPPO Find X5", "oppo", "1080x2400", "450"},
	{"OPPO Find X6", "oppo", "1080x2400", "450"},
	{"OPPO Reno 8", "oppo", "1080x2400", "450"},
	{"vivo X80", "vivo", "1080x2400", "440"},
	{"vivo X90", "vivo", "1080x2400", "440"},
	{"vivo Y78", "vivo", "1080x2400", "400"},
	{"Honor 70", "honor", "1080x2400", "430"},
	{"Honor 90", "honor", "1080x2400", "430"},
	{"Honor Magic5", "honor", "1080x2400", "430"},
	{"Nothing Phone (2)", "nothing", "1080x2400", "440"},
	{"Nothing Phone (1)", "nothing", "1080x2400", "440"},
	{"Realme GT 2", "realme", "1080x2400", "440"},
	{"Realme 11", "realme", "1080x2400", "400"},
	{"Motorola Edge 40", "motorola", "1080x2400", "440"},
	{"Motorola Razr 40", "motorola", "1080x2400", "440"},
	{"Sony Xperia 1 V", "sony", "1080x2400", "440"},
	{"Sony Xperia 5 V", "sony", "1080x2400", "440"},
	{"Nokia X30", "nokia", "1080x2400", "400"},
	{"Nokia G60", "nokia", "1080x2400", "400"},
}

var iosModels = []DeviceModel{
	{"iPhone14,5", "apple", "1170x2532", "460"},
	{"iPhone14,2", "apple", "1170x2532", "460"},
	{"iPhone14,3", "apple", "1170x2532", "460"},
	{"iPhone14,4", "apple", "1170x2532", "460"},
	{"iPhone14,6", "apple", "1170x2532", "460"},
	{"iPhone14,7", "apple", "1170x2532", "460"},
	{"iPhone14,8", "apple", "1284x2778", "458"},
	{"iPad13,4", "apple", "2048x2732", "320"},
	{"iPad13,1", "apple", "1640x2360", "264"},
	{"iPad13,2", "apple", "1640x2360", "264"},
	{"iPad13,10", "apple", "2388x1668", "264"},
	{"iPad13,11", "apple", "2388x1668", "264"},
}

var laptopModels = []DeviceModel{
	{"MacBookPro18,1", "apple", "2560x1600", "220"},
	{"MacBookPro18,2", "apple", "3024x1964", "254"},
	{"MacBookPro18,3", "apple", "3456x2234", "254"},
	{"MacBookPro18,4", "apple", "3024x1964", "254"},
	{"MacBookAir10,1", "apple", "2560x1600", "227"},
	{"XPS-15-9520", "dell", "1920x1200", "180"},
	{"XPS-13-9310", "dell", "1920x1200", "166"},
	{"ThinkPad-X1-Carbon-Gen10", "lenovo", "1920x1200", "170"},
	{"ThinkPad-X1-Carbon-Gen9", "lenovo", "1920x1200", "170"},
	{"Surface-Laptop-5", "microsoft", "2256x1504", "201"},
	{"Surface-Pro-9", "microsoft", "2688x1920", "267"},
	{"HP-Spectre-x360", "hp", "1920x1280", "166"},
	{"ASUS-ZenBook", "asus", "1920x1200", "166"},
}

var regions = []string{"US", "CA", "GB", "DE", "FR", "ID", "MY", "PH", "VN", "TW", "HK"}

type TTDEV struct {
	Did  string
	Iid  string
	Dev  string
	Brnd string
	Osv  string
	Vc   string
	Reg  string
	Ch   string
	App  string
	Ua   string
}

type tiktokStats struct {
	s, f, t, e int64 // Go 1.18 compatibility: standard int64
}

var (
	devPool  []TTDEV
	devIndex int64
)

func init() {
	devPool = make([]TTDEV, 50000)
	for i := range devPool {
		devPool[i] = generateTTDEV()
	}
}

func getDevice() TTDEV {
	idx := atomic.AddInt64(&devIndex, 1)
	return devPool[idx%int64(len(devPool))]
}

func randDigits(n int) string {
	b := make([]byte, n)
	for i := range b {
		b[i] = byte('0' + rand.Intn(10))
	}
	return string(b)
}

func generateTTDEV() TTDEV {
	dt := rand.Intn(3)
	var model, brand, osv, ua, ch string

	switch dt {
	case 0:
		s := androidModels[rand.Intn(len(androidModels))]
		model, brand = s.Model, s.Brand
		osv = []string{"10", "11", "12", "13", "14"}[rand.Intn(5)]
		ua = fmt.Sprintf("com.ss.android.ugc.trill/300804 (Linux; U; Android %s; en_US; %s; Build/RP1A.200720.012; Cronet/58.0.2991.0)", osv, model)
		ch = "googleplay"
	case 1:
		s := iosModels[rand.Intn(len(iosModels))]
		model, brand = s.Model, s.Brand
		osv = []string{"14.8", "15.6", "16.2", "17.0"}[rand.Intn(4)]
		ua = fmt.Sprintf("Trill/300804 (iOS %s)", osv)
		ch = "appstore"
	default:
		s := laptopModels[rand.Intn(len(laptopModels))]
		model, brand = s.Model, s.Brand
		osv = []string{"10", "11", "12"}[rand.Intn(3)]
		ua = fmt.Sprintf("Mozilla/5.0 (%s; OS %s)", brand, osv)
		ch = "desktop"
	}

	return TTDEV{
		Did:  randDigits(19),
		Iid:  randDigits(19),
		Dev:  model,
		Brnd: brand,
		Osv:  osv,
		Vc:   "300804",
		Reg:  regions[rand.Intn(len(regions))],
		Ch:   ch,
		App:  "trill",
		Ua:   ua,
	}
}

func getvid(l string) (string, error) {
	l = strings.TrimSpace(l)
	if l == "" {
		return "", errors.New("link kosong")
	}

	if matched, _ := regexp.MatchString(`^\d{10,30}$`, l); matched {
		return l, nil
	}

	if !strings.HasPrefix(l, "http://") && !strings.HasPrefix(l, "https://") {
		l = "https://" + l
	}

	m := vrgx.FindStringSubmatch(l)
	if len(m) >= 2 {
		return m[1], nil
	}

	c := &http.Client{
		Timeout: 10 * time.Second,
		CheckRedirect: func(req *http.Request, via []*http.Request) error {
			if len(via) >= 10 {
				return errors.New("too many redirects")
			}
			req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")
			return nil
		},
	}

	req, err := http.NewRequest("GET", l, nil)
	if err != nil {
		return "", err
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

	resp, err := c.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	finalURL := resp.Request.URL.String()
	m = vrgx.FindStringSubmatch(finalURL)
	if len(m) >= 2 {
		return m[1], nil
	}

	return "", errors.New("tidak bisa menemukan video ID")
}

func getRealStartCount(link string) int64 {
	if !strings.HasPrefix(link, "http://") && !strings.HasPrefix(link, "https://") {
		link = "https://" + link
	}
	cleanLink := strings.Split(link, "?")[0]
	apiURL := fmt.Sprintf("https://www.tikwm.com/api/?url=%s", url.QueryEscape(cleanLink))

	req, err := http.NewRequest("GET", apiURL, nil)
	if err != nil {
		return 0
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36")

	c := &http.Client{Timeout: 5 * time.Second}
	resp, err := c.Do(req)
	if err != nil {
		return 0
	}
	defer resp.Body.Close()

	body, _ := io.ReadAll(io.LimitReader(resp.Body, 5*1024*1024))

	var result struct {
		Code int `json:"code"`
		Data struct {
			PlayCount int64 `json:"play_count"`
		} `json:"data"`
	}

	if err := json.Unmarshal(body, &result); err == nil {
		if result.Code == 0 && result.Data.PlayCount > 0 {
			return result.Data.PlayCount
		}
	}
	return 0
}

func sendview(c *http.Client, vid string, st *tiktokStats, useProxy bool) {
	build := BUILDS[rand.Intn(len(BUILDS))]
	d := getDevice()
	osv := rand.Intn(7) + 5

	p := url.Values{
		"app_language": {"fr"}, "iid": {d.Iid}, "device_id": {d.Did},
		"channel": {d.Ch}, "device_type": {d.Dev}, "ac": {"wifi"},
		"os_version": {strconv.Itoa(osv)}, "version_code": {strconv.Itoa(build)},
		"app_name": {d.App}, "device_brand": {d.Brnd}, "ssmix": {"a"},
		"device_platform": {"android"}, "aid": {"1180"}, "as": {"a1iosdfgh"}, "cp": {"androide1"},
	}
	h := map[string]string{
		"Host": HOST, "Connection": "keep-alive", "Accept-Encoding": "gzip",
		"X-SS-REQ-TICKET": strconv.FormatInt(time.Now().UnixMilli(), 10),
		"Content-Type":    "application/x-www-form-urlencoded; charset=UTF-8",
		"User-Agent":      d.Ua,
	}
	b := url.Values{
		"manifest_version_code": {strconv.Itoa(build)},
		"update_version_code":   {strconv.Itoa(build) + "0"},
		"play_delta":            {"1"}, "item_id": {vid},
		"version_code": {strconv.Itoa(build)}, "aweme_type": {"0"},
	}

	if useProxy {
		// ============================================
		// JALUR PROXY (HTTPS fallback HTTP, Cek Longgar)
		// ============================================
		u := fmt.Sprintf("https://%s/aweme/v1/aweme/stats?%s", HOST, p.Encode())
		req, _ := http.NewRequest("POST", u, bytes.NewBufferString(b.Encode()))
		for k, v := range h {
			req.Header.Set(k, v)
		}
		resp, err := c.Do(req)
		
		// Fallback ke HTTP biasa jika HTTPS timeout/error di proxy
		if err != nil {
			uHttp := fmt.Sprintf("http://%s/aweme/v1/aweme/stats?%s", HOST, p.Encode())
			reqHttp, _ := http.NewRequest("POST", uHttp, bytes.NewBufferString(b.Encode()))
			for k, v := range h {
				reqHttp.Header.Set(k, v)
			}
			resp, err = c.Do(reqHttp)
		}

		if err != nil {
			if strings.Contains(err.Error(), "timeout") {
				atomic.AddInt64(&st.t, 1)
			} else {
				atomic.AddInt64(&st.e, 1)
			}
			return
		}
		bodyBytes, _ := io.ReadAll(io.LimitReader(resp.Body, 1024))
		io.Copy(io.Discard, resp.Body)
		resp.Body.Close()

		contentType := strings.ToLower(resp.Header.Get("Content-Type"))
		bodyStr := strings.ToLower(string(bodyBytes))

		if resp.StatusCode == 200 && (strings.Contains(contentType, "json") || strings.Contains(bodyStr, "status_code")) && !strings.Contains(bodyStr, "<html") && !strings.Contains(bodyStr, "<!doctype") {
			atomic.AddInt64(&st.s, 1)
		} else {
			atomic.AddInt64(&st.f, 1)
		}
	} else {
		// ============================================
		// JALUR DEFAULT/DIRECT (HTTPS Murni, Cek Strict)
		// ============================================
		u := fmt.Sprintf("https://%s/aweme/v1/aweme/stats?%s", HOST, p.Encode())
		req, _ := http.NewRequest("POST", u, bytes.NewBufferString(b.Encode()))
		for k, v := range h {
			req.Header.Set(k, v)
		}
		resp, err := c.Do(req)
		if err != nil {
			if strings.Contains(err.Error(), "timeout") {
				atomic.AddInt64(&st.t, 1)
			} else {
				atomic.AddInt64(&st.e, 1)
			}
			return
		}
		io.Copy(io.Discard, resp.Body)
		resp.Body.Close()

		contentType := strings.ToLower(resp.Header.Get("Content-Type"))
		if resp.StatusCode == 200 && strings.Contains(contentType, "charset=utf-8") {
			atomic.AddInt64(&st.s, 1)
		} else {
			atomic.AddInt64(&st.f, 1)
		}
	}
}

type clientPool struct {
	clients []*http.Client
	next    int32
}

func parseProxy(proxyStr string) (*url.URL, error) {
	proxyStr = strings.TrimSpace(proxyStr)
	if proxyStr == "" {
		return nil, nil
	}
	// Jika user menyertakan skema socks5 secara eksplisit
	if strings.HasPrefix(proxyStr, "socks5://") {
		return url.Parse(proxyStr)
	}
	parts := strings.Split(proxyStr, ":")
	var proxyUrlStr string
	if len(parts) == 2 {
		proxyUrlStr = fmt.Sprintf("http://%s:%s", parts[0], parts[1])
	} else if len(parts) == 4 {
		proxyUrlStr = fmt.Sprintf("http://%s:%s@%s:%s", url.PathEscape(parts[2]), url.PathEscape(parts[3]), parts[0], parts[1])
	} else {
		if !strings.HasPrefix(proxyStr, "http://") && !strings.HasPrefix(proxyStr, "https://") && !strings.HasPrefix(proxyStr, "socks5://") {
			proxyStr = "http://" + proxyStr
		}
		return url.Parse(proxyStr)
	}
	return url.Parse(proxyUrlStr)
}

func newClientPool(n, conns int, proxyURL *url.URL, deadline int64) *clientPool {
	cp := &clientPool{clients: make([]*http.Client, n)}
	per := (conns + n - 1) / n
	for i := 0; i < n; i++ {
		tp := &http.Transport{
			MaxIdleConns:        per,
			MaxIdleConnsPerHost: per,
			MaxConnsPerHost:     per + 5,
		}
		if proxyURL != nil {
			tp.Proxy = http.ProxyURL(proxyURL)
		}
		cp.clients[i] = &http.Client{Timeout: time.Duration(deadline), Transport: tp}
	}
	return cp
}

func (cp *clientPool) Get() *http.Client {
	return cp.clients[atomic.AddInt32(&cp.next, 1)%int32(len(cp.clients))]
}

func progressStr(sent, total int64) string {
	pct := float64(sent) / float64(total) * 100
	if pct > 100 {
		pct = 100
	}
	return fmt.Sprintf("%d/%d (%.0f%%)", sent, total, pct)
}

func runTikTok(vid string, tgt int, thr int, proxyURL *url.URL) int64 {
	start := time.Now()
	st := &tiktokStats{}

	// Atur timeout/deadline secara dinamis (5s jika direct, 15s jika proxy)
	var deadline int64 = 5 * int64(time.Second)
	useProxy := proxyURL != nil
	if useProxy {
		deadline = 15 * int64(time.Second)
	}

	pool := newClientPool(10, thr, proxyURL, deadline)
	timeout := time.After(90 * time.Second)
	done := make(chan struct{})
	
	go func() {
		t := time.NewTicker(REFRESHRATE)
		defer t.Stop()
		for {
			select {
			case <-t.C:
				s := atomic.LoadInt64(&st.s)
				elapsed := time.Since(start).Seconds()
				r := 0.0
				if elapsed > 0 {
					r = float64(s) / elapsed
				}
				fmt.Printf("\r  views: %s  rate: %.0f/s  ", progressStr(s, int64(tgt)), r)
			case <-done:
				return
			}
		}
	}()

	var wg sync.WaitGroup
	for i := 0; i < thr; i++ {
		wg.Add(1)
		go func() {
			defer wg.Done()
			for {
				if atomic.LoadInt64(&st.s) >= int64(tgt) {
					return
				}
				select {
				case <-timeout:
					return
				default:
					sendview(pool.Get(), vid, st, useProxy)
				}
			}
		}()
	}
	wg.Wait()
	close(done)

	elapsed := time.Since(start).Seconds()
	sent := atomic.LoadInt64(&st.s)
	r := float64(sent) / elapsed
	fmt.Printf("\r  views: %s  rate: %.0f/s\n", progressStr(sent, int64(tgt)), r)
	return sent
}

func getOutboundIP(client *http.Client) string {
	services := []string{
		"http://api.ipify.org",
		"http://icanhazip.com",
		"http://ifconfig.me/ip",
		"http://ident.me",
	}

	for _, serviceURL := range services {
		req, err := http.NewRequest("GET", serviceURL, nil)
		if err != nil {
			continue
		}
		req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36")
		
		resp, err := client.Do(req)
		if err != nil {
			continue
		}
		
		body, err := io.ReadAll(resp.Body)
		resp.Body.Close()
		if err != nil {
			continue
		}
		
		ipStr := strings.TrimSpace(string(body))
		if len(ipStr) > 0 && len(ipStr) <= 45 && !strings.Contains(ipStr, "<") && !strings.Contains(ipStr, "html") && !strings.Contains(ipStr, " {") {
			return ipStr
		}
	}
	return "IP Lookup Failed (Rate Limited / Blocked by providers)"
}

func main() {
	var target string
	var quantity int
	var threads int = 300
	var proxyInput string

	if len(os.Args) >= 3 {
		target = os.Args[1]
		qty, err := strconv.Atoi(os.Args[2])
		if err == nil {
			quantity = qty
		}
		if len(os.Args) >= 4 {
			thr, err := strconv.Atoi(os.Args[3])
			if err == nil {
				threads = thr
			}
		}
		if len(os.Args) >= 5 {
			proxyInput = os.Args[4]
		}
	} else {
		fmt.Println("=================================================")
		fmt.Println("       TIKTOK VIEWS BOOSTER (Go CLI)            ")
		fmt.Println("=================================================")
		fmt.Print("[?] Masukkan Link Video TikTok: ")
		fmt.Scanln(&target)
		fmt.Print("[?] Masukkan Jumlah Views: ")
		fmt.Scanln(&quantity)
		fmt.Print("[?] Masukkan Jumlah Threads (default 300): ")
		var thrInput string
		fmt.Scanln(&thrInput)
		if thrInput != "" {
			thr, err := strconv.Atoi(thrInput)
			if err == nil {
				threads = thr
			}
		}
		fmt.Print("[?] Masukkan Proxy (host:port:user:pw, kosongkan jika tanpa proxy): ")
		fmt.Scanln(&proxyInput)
	}

	if target == "" || quantity <= 0 {
		fmt.Println("[!] Target atau quantity tidak valid!")
		return
	}

	proxyURL, err := parseProxy(proxyInput)
	if err != nil {
		fmt.Printf("[!] Gagal parsing proxy: %v\n", err)
		return
	}
	
	if proxyURL != nil {
		userInfo := ""
		if proxyURL.User != nil {
			userInfo = proxyURL.User.Username() + ":***@"
		}
		fmt.Printf("[+] Outbound Route: Proxy %s://%s%s\n", proxyURL.Scheme, userInfo, proxyURL.Host)
	} else {
		fmt.Println("[+] Outbound Route: Koneksi Langsung (Direct VPS)")
	}

	fmt.Println("[*] Memverifikasi Outbound IP...")
	testPool := newClientPool(1, 1, proxyURL, 5*int64(time.Second))
	outboundIP := getOutboundIP(testPool.Get())
	fmt.Printf("[+] Outbound IP: %s\n", outboundIP)

	fmt.Println("\n[*] Memproses link video...")
	vid, err := getvid(target)
	if err != nil {
		fmt.Printf("[!] Gagal extract video ID: %v\n", err)
		return
	}
	fmt.Printf("[+] Video ID: %s\n", vid)

	fmt.Println("[*] Mengambil Start Count asli via TikWM...")
	startCount := getRealStartCount(target)
	if startCount == 0 {
		fmt.Println("  [!] Start count gagal diambil. Set 0.")
	} else {
		fmt.Printf("  [+] Start Count Asli: %d\n", startCount)
	}

	fmt.Printf("[*] Mulai kirim %d views dengan %d threads...\n", quantity, threads)
	sent := runTikTok(vid, quantity, threads, proxyURL)

	fmt.Println("\n-------------------------------------------------")
	fmt.Printf("[✓] Selesai! Terkirim: %d views\n", sent)
	if startCount > 0 {
		fmt.Printf("[✓] Estimasi Views Akhir: %d\n", startCount+sent)
	}
	fmt.Println("=================================================")
}