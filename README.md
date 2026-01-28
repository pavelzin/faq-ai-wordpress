# WP AI FAQ

Wtyczka WordPress automatycznie generująca FAQ (Frequently Asked Questions) z treści postów przy użyciu OpenAI API. Dodaje structured data (JSON-LD) dla lepszego SEO.

## ✨ Funkcje

- 🤖 **Automatyczne generowanie FAQ** - przy publikowaniu nowych postów
- 📊 **JSON-LD structured data** - lepsze SEO i rich snippets w Google
- ⚡ **Prosty w użyciu** - działa automatycznie po konfiguracji
- �� **Panel administracyjny** - łatwa konfiguracja klucza API
- 🚫 **Brak duplikatów** - nie generuje FAQ ponownie dla istniejących postów
- 📏 **Filtr długości** - pomija krótkie posty (mniej niż 300 znaków)

## 📋 Wymagania

- WordPress 5.0 lub nowszy
- PHP 7.4 lub nowszy  
- Klucz API OpenAI

## 🚀 Instalacja

### Metoda 1: Upload pliku ZIP

1. Pobierz najnowszą wersję z [Releases](https://github.com/pavelzin/faq-ai-wordpress/releases)
2. W panelu WordPress idź do **Wtyczki → Dodaj nową → Wgraj wtyczkę**
3. Wybierz pobrany plik ZIP i kliknij **Zainstaluj**
4. **Aktywuj** wtyczkę

### Metoda 2: Ręczna instalacja

1. Pobierz lub sklonuj to repozytorium
2. Skopiuj folder do katalogu `/wp-content/plugins/wp-ai-faq/`
3. W panelu WordPress idź do **Wtyczki** i aktywuj **WP AI FAQ**

## ⚙️ Konfiguracja

1. Po aktywacji idź do **Ustawienia → WP AI FAQ**
2. Wprowadź swój **klucz API OpenAI**
   - Uzyskaj klucz na: [https://platform.openai.com/api-keys](https://platform.openai.com/api-keys)
3. Kliknij **Zapisz zmiany**

## 📖 Użytkowanie

1. Utwórz nowy post z treścią **dłuższą niż 300 znaków**
2. **Opublikuj** post
3. FAQ zostanie automatycznie wygenerowane i pojawi się **na dole posta**
4. Structured data (JSON-LD) zostanie dodane do kodu dla SEO

### Przykład wygenerowanego FAQ

Na podstawie artykułu o akcyzie na samochody, wtyczka wygeneruje:

**FAQ**
- **Jakie są stawki akcyzy na samochody osobowe w Polsce?**  
  Stawki wynoszą 3,1% dla silników do 2.0l, 18,6% powyżej 2.0l i 0% dla aut elektrycznych.

- **Kto musi płacić akcyzę na samochody?**  
  Akcyza dotyczy osób fizycznych, firm oraz importerów samochodów.

## 🔧 Jak to działa

1. **Hook `save_post`** - reaguje na publikowanie postów
2. **Filtrowanie treści** - usuwa HTML i sprawdza długość
3. **Wywołanie OpenAI API** - model `gpt-4o-mini` generuje FAQ
4. **Parsowanie JSON** - wyciąga dane z odpowiedzi markdown
5. **Zapis do meta** - przechowuje FAQ w `_wp_ai_faq` meta field
6. **Wyświetlanie** - dodaje FAQ na frontend + JSON-LD schema

## 🎯 SEO Korzyści

- **Rich Snippets** - FAQ może być wyświetlane w wynikach Google
- **Structured Data** - JSON-LD schema.org dla lepszej indeksacji  
- **Więcej treści** - dodatkowe słowa kluczowe na stronie
- **User Experience** - szybsze odpowiedzi na częste pytania

## ��️ Rozwijanie

### Funkcje API

- `wp_ai_faq_generate($post_id, $post)` - generuje FAQ dla posta
- `wp_ai_faq_call_openai($content)` - wywołuje OpenAI API
- `wp_ai_faq_display($content)` - wyświetla FAQ na frontend
- `wp_ai_faq_settings_page()` - panel ustawień

### Meta fields

- `_wp_ai_faq` - przechowuje wygenerowane FAQ jako array

## 🔒 Bezpieczeństwo (v1.7.1)

Wersja 1.7.1 zawiera kompleksowe poprawki bezpieczeństwa:

- **Brak hardcoded kluczy** - wymaga konfiguracji własnego klucza API
- **Sanityzacja danych** - ochrona przed XSS w pytaniach i odpowiedziach FAQ
- **Weryfikacja nonce** - ochrona przed CSRF dla wszystkich operacji AJAX
- **Bezpieczne JSON-LD** - prawidłowe kodowanie z flagami bezpieczeństwa
- **Sprawdzanie uprawnień** - tylko autoryzowani użytkownicy mogą generować FAQ
- **Bezpieczne logowanie** - logi tylko w trybie debug, bez wrażliwych danych

### Zalecenia przed produkcją

1. Upewnij się że `WP_DEBUG` jest ustawione na `false`
2. Skonfiguruj własny klucz API OpenAI
3. Używaj HTTPS na stronie

## 🐛 Troubleshooting

### FAQ się nie generuje

1. Sprawdź czy klucz API jest prawidłowy w **Ustawienia → WP AI FAQ**
2. Upewnij się że post ma **więcej niż 300 znaków**
3. Sprawdź **logi błędów** WordPress w `wp-content/debug.log`

### FAQ się nie wyświetla

1. Sprawdź czy jesteś na **pojedynczym poście** (nie archiwum)
2. Upewnij się że FAQ zostało wygenerowane (sprawdź w bazie: meta_key `_wp_ai_faq`)
3. Sprawdź czy motyw nie blokuje filtra `the_content`

### Błędy OpenAI API

- **401 Unauthorized** - nieprawidłowy klucz API
- **429 Rate Limit** - przekroczony limit requestów  
- **500 Internal Error** - problem z serwerem OpenAI

## 📊 Koszty

Wtyczka używa modelu `gpt-4o-mini` który kosztuje:
- **Input**: $0.15 / 1M tokenów
- **Output**: $0.60 / 1M tokenów

Przykładowy post 1000 słów ≈ 1500 tokenów ≈ $0.0001-0.001 za FAQ.

## 🤝 Współpraca

1. **Fork** repozytorium
2. Stwórz **branch** dla swojej funkcji (`git checkout -b feature/amazing-feature`)
3. **Commit** zmiany (`git commit -m 'Add amazing feature'`)
4. **Push** do branch (`git push origin feature/amazing-feature`)
5. Otwórz **Pull Request**

## 📝 Licencja

Ta wtyczka jest udostępniona na licencji **GPL v2 lub nowszej**.

## 👨‍💻 Autor

**Paweł Żin**
- GitHub: [@pavelzin](https://github.com/pavelzin)

## 📞 Wsparcie

Jeśli masz problemy lub pytania:
1. Sprawdź [Issues](https://github.com/pavelzin/faq-ai-wordpress/issues)
2. Utwórz [nowy Issue](https://github.com/pavelzin/faq-ai-wordpress/issues/new)
3. Przejrzyj dokumentację powyżej
