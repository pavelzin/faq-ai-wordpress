# Instrukcja Instalacji WP AI FAQ

## 📦 Szybka Instalacja

### WordPress Admin Panel

1. **Pobierz wtyczkę**
   - Pobierz najnowszą wersję z [GitHub Releases](https://github.com/pavelzin/faq-ai-wordpress/releases)
   - Lub skopiuj plik `wp-ai-faq.php` 

2. **Instalacja przez WordPress**
   - Zaloguj się do panelu WordPress
   - Idź do **Wtyczki → Dodaj nową → Wgraj wtyczkę**
   - Wybierz pobrany plik ZIP
   - Kliknij **Zainstaluj** i **Aktywuj**

3. **Ręczna instalacja**
   - Skopiuj plik `wp-ai-faq.php` do `/wp-content/plugins/wp-ai-faq/`
   - W panelu WordPress idź do **Wtyczki** 
   - Znajdź **WP AI FAQ** i kliknij **Aktywuj**

## 🔑 Konfiguracja

1. **Uzyskaj klucz OpenAI API**
   - Idź na [https://platform.openai.com/api-keys](https://platform.openai.com/api-keys)
   - Zaloguj się lub zarejestruj konto
   - Kliknij **Create new secret key**
   - Skopiuj wygenerowany klucz (zaczyna się od `sk-`)

2. **Wprowadź klucz w WordPress**
   - W panelu WordPress idź do **Ustawienia → WP AI FAQ**
   - Wklej klucz API w pole **Klucz API OpenAI**
   - Kliknij **Zapisz zmiany**

## 🎯 Pierwszy Test

1. **Utwórz testowy post**
   - Idź do **Wpisy → Dodaj nowy**
   - Dodaj tytuł: "Test FAQ"
   - Dodaj treść (minimum 300 znaków):
   ```
   Lorem ipsum dolor sit amet, consectetur adipiscing elit. 
   Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua. 
   Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris. 
   Duis aute irure dolor in reprehenderit in voluptate velit esse cillum 
   dolore eu fugiat nulla pariatur. Excepteur sint occaecat cupidatat 
   non proident, sunt in culpa qui officia deserunt mollit anim.
   ```

2. **Opublikuj post**
   - Kliknij **Opublikuj**
   - Przejdź do opublikowanego posta na froncie strony
   - Przewiń na dół - powinieneś zobaczyć sekcję **FAQ**

## ✅ Weryfikacja

### Sprawdź czy FAQ się generuje:
1. Otwórz pojedynczy post na froncie strony
2. Przewiń na dół treści
3. Poszukaj sekcji z nagłówkiem "FAQ"

### Sprawdź JSON-LD:
1. Otwórz kod źródłowy strony (Ctrl+U)
2. Szukaj `application/ld+json`
3. Powinieneś znaleźć structured data dla FAQ

### Sprawdź w bazie danych:
1. W phpMyAdmin lub podobnym narzędziu
2. Sprawdź tabelę `wp_postmeta`
3. Szukaj wpisów z `meta_key = '_wp_ai_faq'`

## 🐛 Rozwiązywanie problemów

### FAQ się nie generuje
```php
// Sprawdź logi błędów WordPress
tail -f wp-content/debug.log
```

### Włącz debugowanie WordPress
```php
// W wp-config.php dodaj:
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

### Testuj klucz API ręcznie
```bash
curl -X POST https://api.openai.com/v1/chat/completions \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"gpt-4o-mini","messages":[{"role":"user","content":"Test"}],"max_tokens":10}'
```

## 📞 Pomoc

Jeśli wystąpią problemy:
1. Sprawdź [GitHub Issues](https://github.com/pavelzin/faq-ai-wordpress/issues)
2. Przeczytaj [README.md](README.md)
3. Sprawdź logi błędów WordPress
4. Utwórz nowy Issue z opisem problemu
