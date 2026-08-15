package scrape

import (
	"strings"
	"testing"
)

// Банковские сайты почти всегда рендерят одно и то же меню несколько раз
// (десктоп-шапка + мобильное меню + sitemap в футере) — htmlToText не должен
// пересылать этот дубль в AI второй/третий раз.
func TestHtmlToText_DedupRepeatedMenu(t *testing.T) {
	html := `<html><body>
<nav>
<a href="/loans/multi/">Кредит Многоцелевой</a>
<a href="/loans/car/">Автокредит</a>
</nav>
<div class="mobile-menu">
<a href="/loans/multi/">Кредит Многоцелевой</a>
<a href="/loans/car/">Автокредит</a>
</div>
<h1>Автокредит</h1>
<p>Процентная ставка: 22% годовых для электромобилей</p>
<footer>
<a href="/loans/multi/">Кредит Многоцелевой</a>
<a href="/loans/car/">Автокредит</a>
</footer>
</body></html>`

	got := htmlToText(html)

	if n := strings.Count(got, "Кредит Многоцелевой"); n != 1 {
		t.Errorf("Кредит Многоцелевой встречается %d раз, ожидали 1 (дедуп по шапке/моб.меню/футеру): %q", n, got)
	}
	if n := strings.Count(got, "Автокредит"); n != 2 {
		// Один раз из меню (первое вхождение сохраняется) + один раз из <h1> —
		// это разные позиции по смыслу (заголовок страницы), а не повтор меню.
		t.Errorf("Автокредит встречается %d раз, ожидали 2 (меню + заголовок страницы): %q", n, got)
	}
	if !strings.Contains(got, "22% годовых для электромобилей") {
		t.Errorf("продуктовый текст потерян: %q", got)
	}
}

// Короткие лейблы форм («Телефон», «ФИО») легитимно повторяются в разных
// блоках страницы (несколько форм) — dedupMinLen не должен их резать.
func TestHtmlToText_ShortLinesNotDeduped(t *testing.T) {
	html := `<html><body>
<form><p>Телефон</p></form>
<form><p>Телефон</p></form>
</body></html>`

	got := htmlToText(html)

	if n := strings.Count(got, "Телефон"); n != 2 {
		t.Errorf("короткая строка «Телефон» встречается %d раз, ожидали 2 (не дедупим короткие строки): %q", n, got)
	}
}
