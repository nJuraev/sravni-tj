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

// Найдено на amonatbonk.tj: PHP-ошибка сайта (Bitrix) рендерится в
// <h2 style="display:none;">, невидима живым пользователям, но текстовый
// скрейпер её честно тащил в AI — путало экстракцию (постоянные "decode
// extraction: EOF"). Скрытый через display:none контент — вырезать всегда,
// вне зависимости от тега/банка.
func TestHtmlToText_HiddenDisplayNoneStripped(t *testing.T) {
	html := `<html><body>
<div class="container">
<h2 style="display:none;" class="pb-3 animate__fadeIn">[Error]
Undefined constant "DSC" (0)
/var/www/www-root/data/www/amonatbonk.tj/bitrix/template.php:329</h2>
<p>Потребительский кредит</p>
<p style="display: none">Скрытый текст без пробела перед none тоже режем</p>
<div class="d-none questionsCont pt-4 px-5"><pre>[TypeError]
Второй способ спрятать — класс d-none (Bootstrap), не инлайн-style</pre></div>
<div hidden>Третий способ — нативный HTML5-атрибут hidden</div>
<p>24% годовая ставка</p>
</div>
</body></html>`

	got := htmlToText(html)

	for _, bad := range []string{"Error", "bitrix", "Скрытый текст", "TypeError", "класс d-none", "атрибут hidden"} {
		if strings.Contains(got, bad) {
			t.Errorf("скрытый контент (%q) не вырезан: %q", bad, got)
		}
	}
	if !strings.Contains(got, "Потребительский кредит") || !strings.Contains(got, "24% годовая ставка") {
		t.Errorf("видимый продуктовый текст потерян: %q", got)
	}
}

// Найдено на amonatbonk.tj: <div class="position-absolute Img d-none
// d-md-block ..."> с декоративной картинкой — реально видима на десктопе, не
// мусор. Bootstrap использует d-none для responsive show/hide (скрыт на
// мобиле, показан от md) — если рядом есть d-{sm,md,lg,xl,xxl}-{block,flex,...},
// элемент виден на каком-то брейкпоинте и резать по class d-none нельзя
// (в отличие от style=display:none и HTML5 hidden — те однозначны при любом viewport).
func TestHtmlToText_ResponsiveDNoneNotStripped(t *testing.T) {
	html := `<html><body>
<div class="position-absolute Img d-none d-md-block animate__fadeIn">
Десктопная картинка кредита
</div>
<div class="d-none questionsCont">Однозначно скрытый мусор, без responsive-класса</div>
</body></html>`

	got := htmlToText(html)

	if !strings.Contains(got, "Десктопная картинка кредита") {
		t.Errorf("responsive-видимый блок (d-none d-md-block) вырезан по ошибке: %q", got)
	}
	if strings.Contains(got, "Однозначно скрытый") {
		t.Errorf("безусловно скрытый div (голый d-none, без responsive-override) не вырезан: %q", got)
	}
}
