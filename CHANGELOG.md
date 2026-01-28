# Changelog

Wszystkie istotne zmiany w projekcie będą dokumentowane w tym pliku.

Format bazuje na [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
a wersjonowanie zgodne z [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.8.3] - 2026-01-28

### Poprawione
- **FIX: WPML fallback** - FAQ jest teraz pobierane bezpośrednio z bazy danych, omijając filtry WPML które powodowały że tłumaczenia "dziedziczyły" FAQ z oryginalnego posta
- Nowa funkcja `wp_ai_faq_get_faq()` - bezpośrednie zapytanie do `wp_postmeta` bez fallbacku

## [1.8.1] - 2026-01-28

### Poprawione
- **WPML/Polylang: osobne FAQ dla każdej wersji językowej** - meta `_wp_ai_faq` nie jest już synchronizowane między tłumaczeniami
- Dodana kolumna ID w tabeli dla łatwiejszej identyfikacji postów

### Dodane
- Filtr `wpml_sync_custom_field_excluded` - blokuje synchronizację meta FAQ w WPML
- Filtr `pll_copy_post_metas` - blokuje kopiowanie meta FAQ w Polylang

## [1.8.0] - 2026-01-28

### Dodane
- **Zakładki typów postów** - szybkie przełączanie między Post, Page, Custom Post Types
- **Paginacja** - 25 postów na stronę dla lepszej wydajności przy dużej ilości treści
- **Liczniki w zakładkach** - widoczna ilość postów każdego typu
- **Zakładka "Wszystkie"** - podgląd wszystkich włączonych typów naraz

### Zmienione
- Panel administracyjny zoptymalizowany pod kątem wydajności
- Lepsza organizacja interfejsu z nawigacją zakładkową
- Skrócone etykiety w tabeli dla lepszej czytelności

### Poprawione
- Wydajność przy dużej liczbie postów (brak ładowania wszystkich naraz)
- UX - łatwiejsze zarządzanie FAQ dla różnych typów treści

## [1.7.1] - 2026-01-28

### Bezpieczeństwo
- **KRYTYCZNE**: Usunięty hardcoded klucz API OpenAI - wymaga teraz konfiguracji własnego klucza
- Dodana sanityzacja danych FAQ (`sanitize_text_field()` dla pytań, `wp_kses_post()` dla odpowiedzi)
- Dodana weryfikacja nonce dla wszystkich endpointów AJAX (ochrona przed CSRF)
- Naprawione kodowanie JSON-LD z flagami bezpieczeństwa (`JSON_HEX_TAG`, `JSON_HEX_AMP`, etc.)
- Zabezpieczone logowanie - działa tylko gdy `WP_DEBUG` jest włączony
- Dodane sprawdzanie uprawnień użytkownika (`current_user_can()`) przy generowaniu FAQ
- Sanityzacja klucza API przy zapisie (`sanitize_text_field()`)
- Zamienione `wp_add_inline_script()` na bezpieczniejsze `wp_localize_script()`

### Zmienione
- Funkcja logowania `wp_ai_faq_log()` zamiast bezpośrednich wywołań `error_log()`
- Logi nie zawierają już wrażliwych danych (długość klucza API, surowe odpowiedzi)

## [1.7.0] - 2026-01-28

### Dodane
- **Wybór typów postów** - możliwość włączenia/wyłączenia FAQ dla dowolnych custom post types
- **Tryby wyświetlania** - "Front + JSON-LD" lub "Tylko JSON-LD" (ukryte FAQ, tylko schema SEO)
- **Dynamiczna lista modeli OpenAI** - pobierana automatycznie z API, cache 24h
- **Obsługa nowych modeli** - GPT-5, GPT-5.2, GPT-4.1, O1, O3 (max_completion_tokens)
- **Wykrywanie języka** - auto z locale WP, auto z WPML/Polylang, lub ręczny wybór
- **Ustawienie języka FAQ** - wymusza język odpowiedzi niezależnie od treści
- **Przycisk "Odśwież listę modeli"** - ręczne odświeżanie listy z API
- **Panel masowego generowania** - generowanie FAQ dla wielu postów z progress bar
- **Edycja FAQ** - modal do edycji wygenerowanych pytań i odpowiedzi
- **Kolumna "Tryb wyświetlania"** w tabeli postów

### Zmienione
- Zwiększony limit tokenów do 3000
- Timeout zwiększony do 60s dla modeli reasoning
- Prompt zaktualizowany o instrukcję języka ({LANGUAGE} placeholder)
- Sortowanie modeli - najnowsze (GPT-5.2) na górze

### Poprawione
- Obsługa `max_completion_tokens` dla nowych modeli OpenAI (zamiast `max_tokens`)
- Integracja z WPML i Polylang dla wielojęzycznych stron
- Walidacja FAQ przed wyświetleniem (sprawdzanie błędów)

### Kompatybilność
- WordPress 5.0+
- PHP 7.4+
- OpenAI API (GPT-3.5 do GPT-5.2, O1, O3)
- WPML, Polylang, MultilingualPress

## [1.0.0] - 2025-01-25

### Dodane
- Automatyczne generowanie FAQ z treści postów przy użyciu OpenAI API
- Panel administracyjny do konfiguracji klucza API
- JSON-LD structured data dla lepszego SEO
- Filtrowanie krótkich postów (mniej niż 300 znaków)
- Sprawdzanie czy FAQ już istnieje (brak duplikatów)
- Obsługa błędów i logowanie
- Wsparcie dla markdown formatowania z OpenAI
- Frontend wyświetlanie FAQ na pojedynczych postach

### Funkcje
- Hook `save_post` - automatyczne generowanie przy publikowaniu
- Meta field `_wp_ai_faq` - przechowywanie wygenerowanego FAQ
- Filter `the_content` - wyświetlanie FAQ na frontend
- Admin menu w **Ustawienia → WP AI FAQ**
- Walidacja i sanityzacja danych wejściowych
- Timeout 30s dla requestów do OpenAI API
- Obsługa JSON w blokach markdown od OpenAI

### Bezpieczeństwo
- Escape HTML w wyświetlanych FAQ
- Sanityzacja klucza API w panelu admina
- Sprawdzanie uprawnień administratora
- Walidacja JSON przed parsowaniem

### Kompatybilność
- WordPress 5.0+
- PHP 7.4+
- OpenAI API (model gpt-4o-mini)
