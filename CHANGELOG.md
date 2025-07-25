# Changelog

Wszystkie istotne zmiany w projekcie będą dokumentowane w tym pliku.

Format bazuje na [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
a wersjonowanie zgodne z [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
