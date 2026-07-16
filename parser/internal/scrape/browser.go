package scrape

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/chromedp/chromedp"
)

// ModeBrowser — значение bank_source_urls.scraper / bank_parse_instructions.scraper,
// требующее рендер через свой headless-Chrome (см. ModeFirecrawl). Замена
// Firecrawl там, где раньше был он: тот же класс проблем (client-rendered SPA,
// anti-bot), но без внешнего платного сервиса — Chrome поднят своим
// контейнером (docker-compose: chrome, Railway: отдельный always-on сервис).
const ModeBrowser = "browser"

// browserPageTimeout — таймаут на один рендер страницы (навигация + ожидание
// JS): SPA-банкам нужно время на гидратацию, дольше, чем HTTPTimeout Direct.
const browserPageTimeout = 45 * time.Second

// browserSettle — пауза после Navigate, чтобы клиентский JS успел отрендерить
// DOM (курсы/каталог часто подгружаются XHR уже после load).
const browserSettle = 2 * time.Second

// Browser — Scraper поверх удалённого headless Chrome по CDP (DevTools
// Protocol). В отличие от Direct умеет выполнять JS — нужен для SPA-страниц
// банков и как замена платному Firecrawl.
type Browser struct {
	cdpURL     string // HTTP-адрес DevTools (например http://chrome:9222), БЕЗ ws://
	httpClient *http.Client
}

// NewBrowser создаёт Scraper поверх Chrome, доступного по cdpURL (HTTP-адрес
// DevTools). Пустой cdpURL допустим на старте — ошибка вернётся только при
// реальном Scrape/ScrapeRaw (см. cfg.BrowserCDPURL).
func NewBrowser(cdpURL string, httpClient *http.Client) *Browser {
	return &Browser{cdpURL: strings.TrimRight(cdpURL, "/"), httpClient: httpClient}
}

// Scrape реализует Scraper: рендерим страницу и прогоняем тот же
// readability-lite, что и Direct (см. htmlToText в direct.go).
func (b *Browser) Scrape(ctx context.Context, url string) (string, error) {
	raw, err := b.ScrapeRaw(ctx, url)
	if err != nil {
		return "", err
	}
	text := strings.TrimSpace(htmlToText(raw))
	if text == "" {
		return "", fmt.Errorf("browser: пустой текст после очистки")
	}
	return text, nil
}

// ScrapeRaw открывает url в удалённом Chrome и возвращает отрендеренный
// (после выполнения JS) outerHTML документа.
func (b *Browser) ScrapeRaw(ctx context.Context, rawURL string) (string, error) {
	if b.cdpURL == "" {
		return "", fmt.Errorf("browser: BROWSER_CDP_URL не задан")
	}

	ctx, cancel := context.WithTimeout(ctx, browserPageTimeout)
	defer cancel()

	wsURL, err := b.debuggerWSURL(ctx)
	if err != nil {
		return "", fmt.Errorf("browser: devtools ws url: %w", err)
	}

	// Отдельный allocator+tab НА КАЖДЫЙ вызов: контейнер Chrome общий на
	// процесс, но вкладки не переиспользуются между запросами — иначе
	// состояние (cookies/JS) одной страницы протекало бы в другую.
	allocCtx, allocCancel := chromedp.NewRemoteAllocator(ctx, wsURL)
	defer allocCancel()

	taskCtx, taskCancel := chromedp.NewContext(allocCtx)
	defer taskCancel()

	var html string
	err = chromedp.Run(taskCtx,
		chromedp.Navigate(rawURL),
		chromedp.Sleep(browserSettle),
		chromedp.OuterHTML("html", &html, chromedp.ByQuery),
	)
	if err != nil {
		return "", fmt.Errorf("browser: navigate %s: %w", rawURL, err)
	}

	html = strings.TrimSpace(html)
	if html == "" {
		return "", fmt.Errorf("browser: пустой html")
	}
	return html, nil
}

// devtoolsVersion — ответ GET /json/version DevTools HTTP-эндпоинта.
type devtoolsVersion struct {
	WebSocketDebuggerURL string `json:"webSocketDebuggerUrl"`
}

// debuggerWSURL резолвит ws://-адрес браузерного DevTools-сокета по HTTP —
// chromedp.NewRemoteAllocator принимает именно ws-адрес, а не голый http(s)
// хост:порт контейнера.
//
// DevTools отвечает 500 на Host-заголовок с ИМЕНЕМ хоста (Chromium защищается
// от DNS rebinding, принимает только IP-литерал или localhost) — поэтому
// достучаться по DNS-имени контейнера (chrome, chrome.railway.internal)
// напрямую нельзя. Резолвим имя в IP сами и стучимся уже по IP: тогда и
// /json/version отвечает 200, и в webSocketDebuggerUrl приходит тот же IP,
// на который потом успешно коннектится chromedp.
func (b *Browser) debuggerWSURL(ctx context.Context) (string, error) {
	ipURL, err := b.resolveToIP(ctx, b.cdpURL)
	if err != nil {
		return "", fmt.Errorf("resolve: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, http.MethodGet, ipURL+"/json/version", nil)
	if err != nil {
		return "", err
	}
	resp, err := b.httpClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("get %s/json/version: %w", ipURL, err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, 1<<16))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", fmt.Errorf("get %s/json/version: HTTP %d", ipURL, resp.StatusCode)
	}

	var v devtoolsVersion
	if err := json.Unmarshal(raw, &v); err != nil {
		return "", fmt.Errorf("unmarshal %s/json/version: %w", ipURL, err)
	}
	if v.WebSocketDebuggerURL == "" {
		return "", fmt.Errorf("%s/json/version: webSocketDebuggerUrl пуст", ipURL)
	}
	return v.WebSocketDebuggerURL, nil
}

// resolveToIP переписывает хост в rawURL на первый резолвнутый IP (порт и
// схема сохраняются) — см. причину в debuggerWSURL.
func (b *Browser) resolveToIP(ctx context.Context, rawURL string) (string, error) {
	u, err := url.Parse(rawURL)
	if err != nil {
		return "", fmt.Errorf("parse %s: %w", rawURL, err)
	}
	host := u.Hostname()
	if net.ParseIP(host) != nil {
		return rawURL, nil // уже IP — резолвить нечего
	}

	ips, err := net.DefaultResolver.LookupIPAddr(ctx, host)
	if err != nil {
		return "", fmt.Errorf("lookup %s: %w", host, err)
	}
	if len(ips) == 0 {
		return "", fmt.Errorf("lookup %s: пусто", host)
	}

	u.Host = net.JoinHostPort(ips[0].IP.String(), u.Port())
	return u.String(), nil
}
