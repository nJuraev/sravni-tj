package scrape

import (
	"context"
	"fmt"
	"html"
	"io"
	"net/http"
	"net/url"
	"regexp"
	"strings"
	"unicode/utf8"
)

// Direct — свой скрейпер: прямой HTTP GET, без внешних сервисов и без
// оплаты/лимитов. Годится для server-rendered страниц (большинство банков) —
// не умеет рендерить JS и не проходит anti-bot защиты (для таких источников
// в БД выставляется scraper='firecrawl', см. Scrapers.For).
type Direct struct {
	client *http.Client
}

// NewDirect создаёт свой скрейпер.
func NewDirect(client *http.Client) *Direct {
	return &Direct{client: client}
}

// Scrape реализует Scraper: GET + readability-lite (вырезать script/style,
// снять теги, схлопнуть пробелы) — грубый аналог Jina readability-режима,
// достаточный для AI-экстрактора (ему нужен текст, не разметка).
func (d *Direct) Scrape(ctx context.Context, url string) (string, error) {
	raw, err := d.get(ctx, url, 4<<20)
	if err != nil {
		return "", err
	}
	text := strings.TrimSpace(htmlToText(raw))
	if text == "" {
		return "", fmt.Errorf("direct: пустой текст после очистки")
	}
	return text, nil
}

// ScrapeRaw возвращает сырой HTML как есть (без выполнения JS) — нужен там,
// где данные лежат в JSON внутри <script> статически (без клиентского рендера).
func (d *Direct) ScrapeRaw(ctx context.Context, url string) (string, error) {
	raw, err := d.get(ctx, url, 8<<20)
	if err != nil {
		return "", err
	}
	raw = strings.TrimSpace(raw)
	if raw == "" {
		return "", fmt.Errorf("direct: пустой html")
	}
	return raw, nil
}

func (d *Direct) get(ctx context.Context, rawURL string, limit int64) (string, error) {
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		return "", fmt.Errorf("direct: new request: %w", err)
	}
	// UA "браузерный" — часть банковских сайтов режет ответ ботам без него.
	req.Header.Set("User-Agent", "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36")
	// Этот же клиент обслуживает и HTML-страницы, и JSON API (array_path-
	// источники — Арванд/ICB/SSB). Чистый "text/html,application/xhtml+xml"
	// ловил HTTP 406 от Арванда (API стал строго валидировать Accept) —
	// добавлен application/json + */* фолбэк, HTML-сайтам это не мешает
	// (Accept там почти всегда игнорируется).
	req.Header.Set("Accept", "text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.8")

	resp, err := d.client.Do(req)
	if err != nil {
		return "", fmt.Errorf("direct: do: %w", err)
	}
	defer resp.Body.Close()

	raw, _ := io.ReadAll(io.LimitReader(resp.Body, limit))
	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return "", &HTTPError{
			StatusCode: resp.StatusCode,
			RetryAfter: resp.Header.Get("Retry-After"),
			Body:       truncate(string(raw), 500),
		}
	}
	return string(raw), nil
}

var (
	// reCut вырезает целиком script/style/noscript/svg-блоки (включая
	// содержимое — там нет читаемого текста, только код/CSS/иконки).
	reCut     = regexp.MustCompile(`(?is)<(script|style|noscript|svg)[^>]*>.*?</(script|style|noscript|svg)>`)
	reComment = regexp.MustCompile(`(?s)<!--.*?-->`)
	// reNoiseBlocks вырезает известные шумные блоки по id — FAQ-аккордеон и
	// форма заявки/звонка (найдено на ibt.tj: 4 FAQ + 9 форм на одной странице,
	// +20-23% экономии сверх dedupLines, см. разбор /credits/kredit-na-...).
	// Список — накопительный allowlist id'шников, замеченных на реальных
	// сайтах; на банках без такого id — no-op, не риск (в отличие от
	// css_hints/goquery — это НЕ выбор "основного контента", а вырезание
	// заведомо нерелевантного, худший случай при редизайне — просто перестанет
	// матчиться). Не вложены в другие <section> — non-greedy безопасен.
	reNoiseBlocks = regexp.MustCompile(`(?is)<section\s+id="(?:faq-section|cform)"[^>]*>.*?</section>`)
	// reHeader/reFooter/reNav вырезают семантические HTML5-теги шапки/футера/
	// навигации целиком — по спецификации это НИКОГДА не основной контент,
	// в отличие от css_hints (выбор "главного" контейнера) это не гадание,
	// а вырезание заведомо служебного. Проверено на 9 живых страницах банков
	// (humo/imon/spitamenbank/alif/ibt/oriyonbank) — теги сбалансированы,
	// не вложены проблемно. На страницах без этих тегов — no-op.
	//
	// Три ОТДЕЛЬНЫХ regex, не одна alternation-группа: если <nav> вложен
	// внутри <header> (частый случай), non-greedy в общей группе остановился
	// бы на первом попавшемся </nav>, обрубив <header> раньше времени и
	// оставив кусок футера "снаружи". Раздельные последовательные проходы
	// (header, затем footer, затем nav) корректно съедают вложенность:
	// header-проход поглощает вложенный nav целиком (его закрывающий тег
	// header-регексу не важен), к nav-проходу извне уже ничего не остаётся.
	reHeader = regexp.MustCompile(`(?is)<header[^>]*>.*?</header>`)
	reFooter = regexp.MustCompile(`(?is)<footer[^>]*>.*?</footer>`)
	reNav    = regexp.MustCompile(`(?is)<nav[^>]*>.*?</nav>`)
	// RE2 (Go regexp) не умеет backreferences — кавычки-открывашка/закрывашка
	// проверяются двумя альтернативами, а не \1.
	reAnchor     = regexp.MustCompile(`(?is)<a\s+[^>]*?href\s*=\s*(?:"([^"]*)"|'([^']*)')[^>]*>(.*?)</a>`)
	reBlockTag   = regexp.MustCompile(`(?i)</(p|div|section|article|li|tr|h1|h2|h3|h4|h5|h6|br|table|ul|ol)>`)
	reTag        = regexp.MustCompile(`(?s)<[^>]+>`)
	reBlankLines = regexp.MustCompile(`\n{3,}`)
	reSpaces     = regexp.MustCompile(`[ \t]{2,}`)
	// reFileLink — ссылка на файл (документ/изображение/архив), не на HTML-
	// страницу продукта — шум для discovery (никогда не страница продукта) и
	// для ctaTextOnly (см. ниже). Проверяется по хвосту пути ДО query/fragment.
	reFileLink = regexp.MustCompile(`(?i)\.(pdf|docx?|xlsx?|pptx?|png|jpe?g|gif|svg|webp|ico|bmp|zip|rar|7z|mp3|mp4|avi|mov)(\?|#|$)`)
	// reNoiseScheme — телефон/мессенджер-схемы в href (banner "Позвоните нам",
	// WhatsApp-виджеты и т.п.) — для discovery шум: никогда не страница продукта.
	reNoiseScheme = regexp.MustCompile(`(?i)^(tel|mailto|sms|whatsapp):`)
)

// noiseHosts — хосты соцсетей/мессенджеров, которые баннерами/футерами
// попадают в extractLinksOnly, но никогда не ведут на страницу продукта.
var noiseHosts = map[string]bool{
	"wa.me": true, "t.me": true, "telegram.me": true,
	"facebook.com": true, "instagram.com": true, "youtube.com": true,
	"twitter.com": true, "x.com": true, "vk.com": true,
	"linkedin.com": true, "ok.ru": true,
}

// isNoiseHref — используется только discovery-путём (extractLinksOnly):
// телефон/мессенджер-схемы и соцсети/мессенджеры по хосту. Относительные
// href пропускаем без host-проверки — их отсеет resolveAndFilter в
// discover.go (сравнение с зарегистрированным доменом банка).
func isNoiseHref(href string) bool {
	if reNoiseScheme.MatchString(href) {
		return true
	}
	u, err := url.Parse(href)
	if err != nil || u.Host == "" {
		return false
	}
	host := strings.TrimPrefix(strings.ToLower(u.Hostname()), "www.")
	return noiseHosts[host]
}

// ctaTextOnly — тексты кнопок-действий (заявка/оформление), которые
// повторяются на КАЖДОЙ карточке/блоке продукта и сами по себе не несут
// различающей информации — в отличие от текста меню, где текст ссылки и есть
// нужные данные (название продукта). Держим их как обычный текст (без
// markdown-ссылки) в парсинге ПРОДУКТОВ: иначе AI видит N визуально разных
// ссылок на разные url и может счесть карточки отдельными продуктами вместо
// одного продукта с несколькими тарифами (см. amonatbonk.tj/ru/personal/
// hypothec/ — 6 карточек «Ипотечный кредит N», у каждой своя кнопка
// «Оформить»/«Подробнее» на СВОЮ tj-подстраницу). discovery эту функцию не
// использует вообще (extractLinksOnly ниже) — там наоборот, ссылка это и есть
// искомые данные, включая на страницы-карточки.
var ctaTextOnly = map[string]bool{
	"оформить":         true,
	"оформить заявку":  true,
	"подать заявку":    true,
	"подать заявление": true,
	"отправить заявку": true,
	"заявка":           true,
	"подробнее":        true,
	"узнать больше":    true,
	"дархост фиристодан": true, // тадж. «отправить заявку»
	"дархост намоед":     true, // тадж. «подать заявку»
}

// linkify заменяет <a href="URL">текст</a> на markdown-ссылку [текст](URL)
// ДО общей зачистки тегов ниже — используется ТОЛЬКО обычным парсингом
// ПРОДУКТОВ (htmlToText/Direct.Scrape/Browser.Scrape), не discovery (см.
// extractLinksOnly). Без этого для страниц, где описание продукта САМО
// содержит осмысленную ссылку, текст ссылки терялся бы без контекста —
// оставляем URL на случай, если он всё же пригодится модели.
//
// Исключение — ctaTextOnly (кнопки «Оформить»/«Подробнее» и т.п.): для них
// URL сознательно ОТБРАСЫВАЕТСЯ, остаётся только текст без markdown-ссылки —
// см. комментарий у ctaTextOnly.
func linkify(s string) string {
	return reAnchor.ReplaceAllStringFunc(s, func(m string) string {
		sub := reAnchor.FindStringSubmatch(m)
		href := sub[1]
		if href == "" {
			href = sub[2]
		}
		href = strings.TrimSpace(href)
		text := strings.TrimSpace(reSpaces.ReplaceAllString(reTag.ReplaceAllString(sub[3], " "), " "))
		if text == "" {
			return " "
		}
		if href == "" || strings.HasPrefix(href, "#") || strings.HasPrefix(href, "javascript:") || ctaTextOnly[strings.ToLower(text)] {
			return " " + text + " "
		}
		return " [" + text + "](" + href + ") "
	})
}

// extractLinksOnly — вход для discovery (internal/discover): вместо полного
// текста страницы отдаёт ТОЛЬКО список ссылок вида "[текст](url)", один на
// строку, без окружающей прозы (новости, курсы валют, соцсети, футер-текст и
// т.п. — discovery это всё игнорирует, зачем тратить на них токены и риск,
// что AI отвлечётся на нерелевантный текст). Критично сохраняет href — без
// этого AI вынужден УГАДЫВАТЬ URL по паттерну текста (поймано вживую на
// eskhata.com: 7 «найденных» ссылок на кредиты оказались 404, AI их
// придумал). Ссылки на файлы (reFileLink — pdf/картинки/архивы) отфильтрованы:
// никогда не страница продукта. Относительные href не резолвим —
// resolveAndFilter в discover.go уже это делает.
func extractLinksOnly(raw string) string {
	s := reCut.ReplaceAllString(raw, "\n")
	s = reComment.ReplaceAllString(s, "")

	seen := make(map[string]bool)
	var out []string
	for _, sub := range reAnchor.FindAllStringSubmatch(s, -1) {
		href := sub[1]
		if href == "" {
			href = sub[2]
		}
		href = strings.TrimSpace(href)
		text := strings.TrimSpace(reSpaces.ReplaceAllString(reTag.ReplaceAllString(sub[3], " "), " "))
		if href == "" || text == "" || strings.HasPrefix(href, "#") || strings.HasPrefix(href, "javascript:") {
			continue
		}
		if reFileLink.MatchString(href) {
			continue
		}
		if isNoiseHref(href) {
			continue
		}
		line := "[" + text + "](" + href + ")"
		if seen[line] {
			continue
		}
		seen[line] = true
		out = append(out, line)
	}
	return strings.Join(out, "\n")
}

// dedupMinLen — минимальная длина строки В РУНАХ (не байтах — текст в основном
// кириллический, byte-len был бы вдвое строже) для дедупа повторов (см.
// dedupLines). Короче — не трогаем: короткие лейблы форм («Телефон», «ФИО»)
// легитимно повторяются в разных блоках страницы, это не шум шапки/меню/футера.
const dedupMinLen = 20

// htmlToText — readability-lite без внешних зависимостей: не ищет "основной
// контент" по DOM-скорингу (как Jina/Readability), просто снимает разметку и
// шум (вырезает <header>/<footer>/<nav> целиком — для парсинга ПРОДУКТОВ эти
// теги никогда не несут условий продукта), плюс сохраняет ссылки как markdown
// (см. linkify). Используется ТОЛЬКО обычным парсингом продуктов
// (Direct.Scrape/Browser.Scrape) — discovery идёт через extractLinksOnly
// (другой вход: нужны ссылки из шапки/меню, которые здесь вырезаются).
func htmlToText(raw string) string {
	s := reCut.ReplaceAllString(raw, "\n")
	s = reNoiseBlocks.ReplaceAllString(s, "\n")
	s = reHeader.ReplaceAllString(s, "\n")
	s = reFooter.ReplaceAllString(s, "\n")
	s = reNav.ReplaceAllString(s, "\n")
	s = reComment.ReplaceAllString(s, "")
	s = linkify(s)
	s = reBlockTag.ReplaceAllString(s, "\n")
	s = reTag.ReplaceAllString(s, " ")
	s = html.UnescapeString(s)
	s = reSpaces.ReplaceAllString(s, " ")
	lines := strings.Split(s, "\n")
	out := make([]string, 0, len(lines))
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line != "" {
			out = append(out, line)
		}
	}
	out = dedupLines(out)
	return reBlankLines.ReplaceAllString(strings.Join(out, "\n"), "\n\n")
}

// ScrapeForLinks — вход для discovery (internal/discover): вместо полного
// текста страницы (htmlToText) отдаёт ТОЛЬКО список ссылок вида
// "[текст](url)" (см. extractLinksOnly) — ни прозы, ни курсов валют, ни
// футера, ни <header>/<nav> НЕ вырезаны (наоборот, специально не трогаем —
// именно там части банков держат меню каталога, см. историю правки). Работает
// поверх ScrapeRaw любого Scraper (Direct/Browser/Firecrawl), поэтому не
// завязан на конкретную реализацию.
func ScrapeForLinks(ctx context.Context, s Scraper, url string) (string, error) {
	raw, err := s.ScrapeRaw(ctx, url)
	if err != nil {
		return "", err
	}
	text := strings.TrimSpace(extractLinksOnly(raw))
	if text == "" {
		return "", fmt.Errorf("scrape: пустой список ссылок после очистки (discovery)")
	}
	return text, nil
}

// dedupLines вырезает повторные вхождения длинных строк внутри одной страницы.
// Банковские сайты почти всегда рендерят одно и то же меню несколько раз
// (десктоп-шапка + мобильное меню + sitemap в футере — see car_prompt.txt на
// eskhata.com: те же пункты меню втроём), это чистый шум для AI-экстрактора
// и лишние токены. Дедуп без хардкода конкретных строк — держит первое
// вхождение, режет остальные.
func dedupLines(lines []string) []string {
	seen := make(map[string]bool, len(lines))
	out := make([]string, 0, len(lines))
	for _, line := range lines {
		if utf8.RuneCountInString(line) >= dedupMinLen {
			if seen[line] {
				continue
			}
			seen[line] = true
		}
		out = append(out, line)
	}
	return out
}
