package scrape

import (
	"crypto/tls"
	"crypto/x509"
	"encoding/pem"
	"fmt"
	"io"
	"net/http"
	"sync"
	"time"
)

// NewHTTPClient — общий *http.Client для всего процесса (discover/parser/
// rates): тот же http.DefaultTransport (пул соединений, proxy из окружения),
// но с TLS-проверкой через AIA-фолбэк (см. NewAIATLSConfig). Per-call
// таймауты — через context в пайплайне, клиентский Timeout не выставляем
// (иначе перекроет context).
func NewHTTPClient() *http.Client {
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.TLSClientConfig = NewAIATLSConfig()
	return &http.Client{Transport: transport}
}

// AIA chasing — фолбэк на случай, когда сервер не присылает нужный
// intermediate-сертификат вообще (Арванд), либо присылает чужой/просроченный
// (ActivBank: leaf выпущен Sectigo, а в цепочке — левый просроченный
// Let's Encrypt intermediate, оставшийся от смены CA). Браузеры и Windows это
// втихую чинят сами (AIA fetching: докачивают ПРАВИЛЬНЫЙ intermediate по URL
// из Authority Information Access самого leaf-сертификата, игнорируя то, что
// прислал сервер) — Go crypto/tls так не умеет, поэтому делаем это руками.
//
// Проверено живьём на обоих банках: Арванд (1 сертификат в цепочке, нет
// intermediate вовсе) и ActivBank (3 сертификата, но 2-й/3-й — чужой CA,
// просрочены). Оба чинятся одним и тем же фолбэком.

// aiaHTTPTimeout — таймаут на докачку intermediate-сертификата по AIA URL.
const aiaHTTPTimeout = 10 * time.Second

// aiaCache — докачанные intermediate-сертификаты по URL, в рамках процесса.
// Банк использует один и тот же (битый) intermediate на все свои страницы —
// незачем качать заново на каждый запрос.
var (
	aiaCacheMu sync.Mutex
	aiaCache   = map[string]*x509.Certificate{}
)

// NewAIATLSConfig — TLS-конфиг с ручной проверкой (InsecureSkipVerify=true
// отключает автопроверку крипто-стека — ВСЯ проверка идёт в
// VerifyPeerCertificate, "insecure" тут не означает "без проверки").
//
// Экспортирован специально: используется НЕ только в Direct-скрейпере, но и
// в общем *http.Client, который cmd/parser и cmd/rates передают напрямую в
// прямые HTTP-фетчи (array-split, header-правило, rate_rule — internal/
// parser/arraysplit.go, parser.go, internal/rates/deterministic.go), минуя
// scrape.Scraper вообще. Та же TLS-проблема (Арванд/ActivBank) там тоже
// всплывает — один и тот же фикс, один клиент на весь процесс.
func NewAIATLSConfig() *tls.Config {
	return &tls.Config{
		InsecureSkipVerify: true, //nolint:gosec // проверяем вручную ниже, см. verifyWithAIAFallback
		VerifyPeerCertificate: verifyWithAIAFallback,
	}
}

// verifyWithAIAFallback: сначала обычная проверка с тем, что прислал сервер
// (штатный путь, подавляющее большинство сайтов); если не сошлось — по
// одному пробуем AIA-урлы из leaf-сертификата, докачивая КОНКРЕТНО тот
// intermediate, которым leaf был подписан на самом деле.
func verifyWithAIAFallback(rawCerts [][]byte, _ [][]*x509.Certificate) error {
	if len(rawCerts) == 0 {
		return fmt.Errorf("aia: сервер не прислал сертификат")
	}
	leaf, err := x509.ParseCertificate(rawCerts[0])
	if err != nil {
		return fmt.Errorf("aia: parse leaf: %w", err)
	}

	roots, err := x509.SystemCertPool()
	if err != nil || roots == nil {
		roots = x509.NewCertPool()
	}

	serverIntermediates := x509.NewCertPool()
	for _, raw := range rawCerts[1:] {
		if c, err := x509.ParseCertificate(raw); err == nil {
			serverIntermediates.AddCert(c)
		}
	}
	if _, err := leaf.Verify(x509.VerifyOptions{Roots: roots, Intermediates: serverIntermediates}); err == nil {
		return nil
	}

	var lastErr error
	for _, aiaURL := range leaf.IssuingCertificateURL {
		inter, err := fetchIntermediate(aiaURL)
		if err != nil {
			lastErr = err
			continue
		}
		pool := x509.NewCertPool()
		pool.AddCert(inter)
		if _, err := leaf.Verify(x509.VerifyOptions{Roots: roots, Intermediates: pool}); err == nil {
			return nil
		}
		lastErr = err
	}

	if lastErr != nil {
		return fmt.Errorf("aia: цепочка не построена (сервер прислал неверный/неполный intermediate, AIA-фолбэк тоже не помог): %w", lastErr)
	}
	return fmt.Errorf("aia: цепочка не построена, и у сертификата нет AIA-урла для фолбэка")
}

// fetchIntermediate качает intermediate-сертификат по AIA URL (DER или PEM
// — оба формата встречаются на практике) с кэшем в рамках процесса.
func fetchIntermediate(url string) (*x509.Certificate, error) {
	aiaCacheMu.Lock()
	if c, ok := aiaCache[url]; ok {
		aiaCacheMu.Unlock()
		return c, nil
	}
	aiaCacheMu.Unlock()

	client := &http.Client{Timeout: aiaHTTPTimeout}
	resp, err := client.Get(url)
	if err != nil {
		return nil, fmt.Errorf("aia: докачка %s: %w", url, err)
	}
	defer resp.Body.Close()
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("aia: докачка %s: HTTP %d", url, resp.StatusCode)
	}

	raw, err := io.ReadAll(io.LimitReader(resp.Body, 1<<20))
	if err != nil {
		return nil, fmt.Errorf("aia: чтение %s: %w", url, err)
	}

	der := raw
	if block, _ := pem.Decode(raw); block != nil {
		der = block.Bytes
	}
	cert, err := x509.ParseCertificate(der)
	if err != nil {
		return nil, fmt.Errorf("aia: parse intermediate %s: %w", url, err)
	}

	aiaCacheMu.Lock()
	aiaCache[url] = cert
	aiaCacheMu.Unlock()
	return cert, nil
}
