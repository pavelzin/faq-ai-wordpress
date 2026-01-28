<?php
/*
Plugin Name: WP AI FAQ Enhanced
Description: Automatyczne generowanie FAQ z AI dla nowych postów + panel masowego generowania dla istniejących treści + edycja FAQ. Używa OpenAI do tworzenia pytań i odpowiedzi z JSON-LD dla SEO.
Version: 1.7
Author: Paweł Zinkiewicz
*/
if (!defined('ABSPATH')) exit;

add_action('save_post', 'wp_ai_faq_generate', 20, 2);

function wp_ai_faq_generate($post_id, $post) {
    if (wp_is_post_revision($post_id) || $post->post_status !== 'publish') return;

    // Sprawdź czy typ posta jest włączony
    $post_types_settings = get_option('wp_ai_faq_post_types', [
        'post' => ['enabled' => true, 'display_mode' => 'front_json'],
        'page' => ['enabled' => true, 'display_mode' => 'front_json']
    ]);
    
    if (!isset($post_types_settings[$post->post_type]) || !$post_types_settings[$post->post_type]['enabled']) {
        return; // Typ posta nie jest włączony
    }

    // Sprawdź czy FAQ już istnieje
    $existing_faq = get_post_meta($post_id, '_wp_ai_faq', true);
    if ($existing_faq) return;

    $content = strip_tags($post->post_content);
    if (strlen($content) < 300) return; // nie generuj dla krótkich postów

    $faq = wp_ai_faq_call_openai($content, $post_id);
    if (!$faq) return;

    update_post_meta($post_id, '_wp_ai_faq', $faq);
}

function wp_ai_faq_call_openai($content, $post_id = null) {
    // Pobierz API key z ustawień lub użyj domyślnego
    $custom_api_key = get_option('wp_ai_faq_api_key', '');
    $default_api_key = 'YOUR_API_KEY_HERE';
    $api_key = !empty($custom_api_key) ? $custom_api_key : $default_api_key;
    $model = get_option('wp_ai_faq_model', 'gpt-4o-mini');
    
    // Wykryj język
    $language = wp_ai_faq_detect_language($post_id);
    
    $prompt = get_option('wp_ai_faq_prompt', 'ZADANIE: Wygeneruj FAQ (3-5 pytań i odpowiedzi) na podstawie poniższej treści.

ZASADY:
- Odpowiedzi opieraj WYŁĄCZNIE na informacjach z podanego tekstu
- Nie dodawaj informacji spoza tekstu
- Każda odpowiedź powinna mieć 2-3 zdania
- WAŻNE: Odpowiadaj w języku: {LANGUAGE}

FORMAT (TYLKO JSON):
[{"question":"Pytanie?","answer":"Odpowiedź oparta na tekście."}]

ZASADY TECHNICZNE:
- Zwróć TYLKO czysty JSON (bez ```json```)
- Używaj podwójnych cudzysłowów

TREŚĆ:
');
    
    // Zamień placeholder języka
    $prompt = str_replace('{LANGUAGE}', $language, $prompt);
    $prompt .= "\n\n" . $content;
    error_log("FAQ DEBUG: OpenAI function called - API key length: " . strlen($api_key) . ", Model: " . $model);
    
    // Buduj request body - nowe modele (gpt-5*, gpt-4.1*, o1*, o3*) używają max_completion_tokens
    $request_body = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
    ];
    
    // Sprawdź czy to nowy model wymagający max_completion_tokens
    $new_model_prefixes = ['gpt-5', 'gpt-4.1', 'o1', 'o3'];
    $is_new_model = false;
    foreach ($new_model_prefixes as $prefix) {
        if (strpos($model, $prefix) === 0) {
            $is_new_model = true;
            break;
        }
    }
    
    if ($is_new_model) {
        $request_body['max_completion_tokens'] = 3000;
    } else {
        $request_body['max_tokens'] = 3000;
    }
    
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer '.$api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode($request_body),
        'timeout' => 60, // Dłuższy timeout dla modeli reasoning
    ]);

    if (is_wp_error($response)) {
        error_log('OpenAI FAQ Error: ' . $response->get_error_message());
        return ['error' => 'Connection error: ' . $response->get_error_message()];
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        $response_body = wp_remote_retrieve_body($response);
        error_log('OpenAI FAQ HTTP Error ' . $response_code . ': ' . $response_body);
        return ['error' => 'HTTP Error ' . $response_code . ': ' . $response_body];
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!$body) {
        error_log('OpenAI FAQ Error: Empty response body');
        return ['error' => 'Empty response body from OpenAI'];
    }
    
    $raw = $body['choices'][0]['message']['content'] ?? '';
    if (empty($raw)) {
        error_log('OpenAI FAQ Error: Empty content in response');
        return ['error' => 'Empty content in OpenAI response'];
    }

        // Wyciągnij JSON z bloku markdown jeśli jest obecny
    if (preg_match('/```json\s*(.*?)\s*```/s', $raw, $matches)) {
        $raw = $matches[1];
    }

    // Wyczyść JSON przed parsowaniem
    // 1. Usuń znaki kontrolne (oprócz dozwolonych: \n, \r, \t)
    $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);
    
    // 2. Napraw enkodowanie UTF-8
    if (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'auto');
    }
    
    // 3. Usuń invisible characters i whitespace z początku/końca
    $raw = trim($raw);
    
    // 4. Loguj raw content dla debugowania (pierwsze 200 znaków)
    error_log('FAQ DEBUG: Cleaned JSON (first 200 chars): ' . substr($raw, 0, 200));

    $faq = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('OpenAI FAQ JSON Error: ' . json_last_error_msg() . ' | Raw content: ' . $raw);
        return ['error' => 'JSON parsing error: ' . json_last_error_msg()];
    }
    
    return is_array($faq) ? $faq : ['error' => 'Invalid FAQ format returned'];
}

add_filter('the_content', 'wp_ai_faq_display');

function wp_ai_faq_display($content) {
    if (is_singular()) {
        global $post;
        
        // Sprawdź ustawienia dla tego typu posta
        $post_types_settings = get_option('wp_ai_faq_post_types', [
            'post' => ['enabled' => true, 'display_mode' => 'front_json'],
            'page' => ['enabled' => true, 'display_mode' => 'front_json']
        ]);
        
        // Sprawdź czy typ posta jest włączony
        if (!isset($post_types_settings[$post->post_type]) || !$post_types_settings[$post->post_type]['enabled']) {
            return $content;
        }
        
        $display_mode = isset($post_types_settings[$post->post_type]['display_mode']) 
            ? $post_types_settings[$post->post_type]['display_mode'] 
            : 'front_json';
        
        $faq = get_post_meta($post->ID, '_wp_ai_faq', true);
        if ($faq && is_array($faq) && !isset($faq['error'])) {
            $json_ld = ['@context' => 'https://schema.org', '@type' => 'FAQPage', 'mainEntity' => []];
            
            foreach ($faq as $item) {
                $q = esc_html($item['question']);
                $a = esc_html($item['answer']);
                
                $json_ld['mainEntity'][] = [
                    '@type' => 'Question',
                    'name' => $q,
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $a]
                ];
            }
            
            // Tryb: tylko JSON-LD (bez wyświetlania na froncie)
            if ($display_mode === 'json_only') {
                $content .= '<script type="application/ld+json">'.json_encode($json_ld).'</script>';
            } 
            // Tryb: front + JSON-LD (wyświetlanie FAQ + schema)
            else {
                $faq_html = '<div class="wp-ai-faq"><h2>FAQ</h2>';
                
                foreach ($faq as $item) {
                    $q = esc_html($item['question']);
                    $a = esc_html($item['answer']);
                    $faq_html .= "<div class='faq-item'><strong>$q</strong><p>$a</p></div>";
                }
                
                $faq_html .= '<script type="application/ld+json">'.json_encode($json_ld).'</script>';
                $faq_html .= '</div>';
                $content .= $faq_html;
            }
        }
    }
    return $content;
}

// Dodaj menu w panelu administracyjnym
add_action('admin_menu', 'wp_ai_faq_admin_menu');
add_action('admin_init', 'wp_ai_faq_admin_init');

function wp_ai_faq_admin_menu() {
    // Główne menu AI FAQ
    add_menu_page(
        'AI FAQ', 
        'AI FAQ', 
        'manage_options', 
        'wp-ai-faq', 
        'wp_ai_faq_admin_page',
        'dashicons-format-chat',
        30
    );
    
    // Submenu - Ustawienia
    add_submenu_page(
        'wp-ai-faq',
        'Ustawienia AI FAQ',
        'Ustawienia',
        'manage_options',
        'wp-ai-faq-settings',
        'wp_ai_faq_settings_page'
    );
}

function wp_ai_faq_admin_init() {
    register_setting('wp_ai_faq_settings', 'wp_ai_faq_api_key');
    register_setting('wp_ai_faq_settings', 'wp_ai_faq_model');
    register_setting('wp_ai_faq_settings', 'wp_ai_faq_prompt');
    register_setting('wp_ai_faq_settings', 'wp_ai_faq_post_types');
    register_setting('wp_ai_faq_settings', 'wp_ai_faq_language');
}

/**
 * Wykrywa język dla danego posta
 * Obsługuje: WPML, Polylang, MultilingualPress, lub ustawienie globalne
 */
function wp_ai_faq_detect_language($post_id = null) {
    $setting = get_option('wp_ai_faq_language', 'auto');
    
    // Jeśli ustawiony konkretny język - użyj go
    if ($setting !== 'auto' && $setting !== 'auto_post') {
        return $setting;
    }
    
    // Tryb auto_post - wykryj język posta z wtyczek wielojęzycznych
    if ($setting === 'auto_post' && $post_id) {
        // WPML
        if (function_exists('wpml_get_language_information')) {
            $lang_info = wpml_get_language_information(null, $post_id);
            if (!empty($lang_info['language_code'])) {
                return wp_ai_faq_normalize_language_code($lang_info['language_code']);
            }
        }
        
        // Polylang
        if (function_exists('pll_get_post_language')) {
            $lang = pll_get_post_language($post_id, 'slug');
            if ($lang) {
                return wp_ai_faq_normalize_language_code($lang);
            }
        }
        
        // MultilingualPress
        if (function_exists('mlp_get_linked_elements')) {
            // Pobierz język z aktualnego bloga
            $locale = get_blog_option(get_current_blog_id(), 'WPLANG');
            if ($locale) {
                return wp_ai_faq_normalize_language_code(substr($locale, 0, 2));
            }
        }
    }
    
    // Fallback: użyj locale WordPressa
    $locale = get_locale();
    return wp_ai_faq_normalize_language_code(substr($locale, 0, 2));
}

/**
 * Normalizuje kod języka do pełnej nazwy
 */
function wp_ai_faq_normalize_language_code($code) {
    $languages = [
        'pl' => 'polski',
        'en' => 'angielski',
        'de' => 'niemiecki',
        'fr' => 'francuski',
        'es' => 'hiszpański',
        'it' => 'włoski',
        'pt' => 'portugalski',
        'nl' => 'holenderski',
        'cs' => 'czeski',
        'sk' => 'słowacki',
        'uk' => 'ukraiński',
        'ru' => 'rosyjski',
        'sv' => 'szwedzki',
        'no' => 'norweski',
        'da' => 'duński',
        'fi' => 'fiński',
    ];
    
    $code = strtolower(substr($code, 0, 2));
    return isset($languages[$code]) ? $languages[$code] : $code;
}

/**
 * Pobiera listę modeli GPT z API OpenAI
 * Cache'uje wyniki na 24 godziny
 */
function wp_ai_faq_get_openai_models() {
    // Sprawdź cache
    $cached_models = get_transient('wp_ai_faq_openai_models');
    if ($cached_models !== false) {
        return $cached_models;
    }
    
    // Pobierz API key
    $custom_api_key = get_option('wp_ai_faq_api_key', '');
    $default_api_key = 'YOUR_API_KEY_HERE';
    $api_key = !empty($custom_api_key) ? $custom_api_key : $default_api_key;
    
    // Pobierz modele z API
    $response = wp_remote_get('https://api.openai.com/v1/models', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'timeout' => 15,
    ]);
    
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        // Zwróć domyślną listę jeśli API nie odpowiada
        return wp_ai_faq_get_default_models();
    }
    
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!$body || !isset($body['data'])) {
        return wp_ai_faq_get_default_models();
    }
    
    // Filtruj tylko modele GPT do chatu
    $gpt_models = [];
    $chat_model_prefixes = ['gpt-3.5', 'gpt-4', 'gpt-5', 'o1', 'o3'];
    $exclude_patterns = ['instruct', 'vision', 'realtime', 'audio', 'search', 'edit', 'embed', 'tts', 'whisper', 'dall-e', 'babbage', 'davinci', 'transcribe', 'image', 'diarize'];
    
    foreach ($body['data'] as $model) {
        $model_id = $model['id'];
        
        // Sprawdź czy to model do chatu
        $is_chat_model = false;
        foreach ($chat_model_prefixes as $prefix) {
            if (strpos($model_id, $prefix) === 0) {
                $is_chat_model = true;
                break;
            }
        }
        
        if (!$is_chat_model) continue;
        
        // Wyklucz nieodpowiednie modele
        $exclude = false;
        foreach ($exclude_patterns as $pattern) {
            if (stripos($model_id, $pattern) !== false) {
                $exclude = true;
                break;
            }
        }
        
        if ($exclude) continue;
        
        $gpt_models[] = [
            'id' => $model_id,
            'name' => wp_ai_faq_format_model_name($model_id),
            'created' => $model['created'] ?? 0,
        ];
    }
    
    // Sortuj - najnowsze na górze
    usort($gpt_models, function($a, $b) {
        // Priorytet dla popularnych modeli (najnowsze najpierw)
        $priority = [
            'gpt-5.2' => 1, 'gpt-5.2-pro' => 2,
            'gpt-5.1' => 3, 'gpt-5' => 4, 'gpt-5-pro' => 5, 'gpt-5-mini' => 6, 'gpt-5-nano' => 7,
            'gpt-4o-mini' => 10, 'gpt-4o' => 11, 'gpt-4.1' => 12, 'gpt-4.1-mini' => 13,
            'gpt-4-turbo' => 20, 'gpt-4' => 21,
            'o3' => 30, 'o3-mini' => 31, 'o1' => 32, 'o1-pro' => 33, 'o1-mini' => 34,
            'gpt-3.5-turbo' => 50
        ];
        $a_priority = $priority[$a['id']] ?? 100;
        $b_priority = $priority[$b['id']] ?? 100;
        
        if ($a_priority !== $b_priority) {
            return $a_priority - $b_priority;
        }
        
        return $b['created'] - $a['created'];
    });
    
    // Cache na 24 godziny
    set_transient('wp_ai_faq_openai_models', $gpt_models, DAY_IN_SECONDS);
    
    return $gpt_models;
}

/**
 * Formatuje nazwę modelu do wyświetlenia
 */
function wp_ai_faq_format_model_name($model_id) {
    $descriptions = [
        // GPT-5.2
        'gpt-5.2' => 'GPT-5.2 (najnowszy) ⭐',
        'gpt-5.2-pro' => 'GPT-5.2 Pro (premium)',
        'gpt-5.2-codex' => 'GPT-5.2 Codex (kod)',
        
        // GPT-5.1
        'gpt-5.1' => 'GPT-5.1',
        'gpt-5.1-codex' => 'GPT-5.1 Codex',
        'gpt-5.1-codex-max' => 'GPT-5.1 Codex Max',
        'gpt-5.1-codex-mini' => 'GPT-5.1 Codex Mini',
        
        // GPT-5
        'gpt-5' => 'GPT-5',
        'gpt-5-pro' => 'GPT-5 Pro',
        'gpt-5-mini' => 'GPT-5 Mini (szybki)',
        'gpt-5-nano' => 'GPT-5 Nano (najtańszy)',
        'gpt-5-codex' => 'GPT-5 Codex',
        
        // GPT-4.1
        'gpt-4.1' => 'GPT-4.1',
        'gpt-4.1-mini' => 'GPT-4.1 Mini',
        'gpt-4.1-nano' => 'GPT-4.1 Nano',
        
        // GPT-4o
        'gpt-4o-mini' => 'GPT-4o Mini (szybki, tani)',
        'gpt-4o' => 'GPT-4o',
        'gpt-4o-2024-11-20' => 'GPT-4o (Nov 2024)',
        'gpt-4o-2024-08-06' => 'GPT-4o (Aug 2024)',
        'gpt-4o-2024-05-13' => 'GPT-4o (May 2024)',
        
        // GPT-4 Turbo
        'gpt-4-turbo' => 'GPT-4 Turbo',
        'gpt-4-turbo-preview' => 'GPT-4 Turbo Preview',
        'gpt-4-turbo-2024-04-09' => 'GPT-4 Turbo (Apr 2024)',
        'gpt-4' => 'GPT-4',
        'gpt-4-0613' => 'GPT-4 (Jun 2023)',
        
        // GPT-3.5
        'gpt-3.5-turbo' => 'GPT-3.5 Turbo (legacy)',
        'gpt-3.5-turbo-0125' => 'GPT-3.5 Turbo (Jan 2024)',
        
        // O-series (reasoning)
        'o3' => 'O3 (reasoning)',
        'o3-mini' => 'O3 Mini (reasoning)',
        'o1' => 'O1 (reasoning)',
        'o1-pro' => 'O1 Pro (reasoning, premium)',
        'o1-mini' => 'O1 Mini (reasoning)',
        'o1-preview' => 'O1 Preview',
    ];
    
    if (isset($descriptions[$model_id])) {
        return $descriptions[$model_id];
    }
    
    // Automatyczne formatowanie dla nieznanych modeli
    $name = str_replace(['gpt-', '-'], ['GPT-', ' '], $model_id);
    return ucwords($name);
}

/**
 * Domyślna lista modeli (fallback gdy API niedostępne)
 * Aktualizacja: Styczeń 2026
 */
function wp_ai_faq_get_default_models() {
    return [
        // GPT-5.2 (najnowsze)
        ['id' => 'gpt-5.2', 'name' => 'GPT-5.2 (najnowszy) ⭐'],
        ['id' => 'gpt-5.2-pro', 'name' => 'GPT-5.2 Pro (premium)'],
        
        // GPT-5.1
        ['id' => 'gpt-5.1', 'name' => 'GPT-5.1'],
        
        // GPT-5
        ['id' => 'gpt-5', 'name' => 'GPT-5'],
        ['id' => 'gpt-5-pro', 'name' => 'GPT-5 Pro'],
        ['id' => 'gpt-5-mini', 'name' => 'GPT-5 Mini (szybki)'],
        ['id' => 'gpt-5-nano', 'name' => 'GPT-5 Nano (najtańszy)'],
        
        // GPT-4.1
        ['id' => 'gpt-4.1', 'name' => 'GPT-4.1'],
        ['id' => 'gpt-4.1-mini', 'name' => 'GPT-4.1 Mini'],
        
        // GPT-4o
        ['id' => 'gpt-4o-mini', 'name' => 'GPT-4o Mini (szybki, tani)'],
        ['id' => 'gpt-4o', 'name' => 'GPT-4o'],
        
        // GPT-4
        ['id' => 'gpt-4-turbo', 'name' => 'GPT-4 Turbo'],
        ['id' => 'gpt-4', 'name' => 'GPT-4'],
        
        // GPT-3.5 (legacy)
        ['id' => 'gpt-3.5-turbo', 'name' => 'GPT-3.5 Turbo (legacy)'],
        
        // Modele Reasoning (O-series)
        ['id' => 'o3', 'name' => 'O3 (reasoning)'],
        ['id' => 'o3-mini', 'name' => 'O3 Mini (reasoning)'],
        ['id' => 'o1', 'name' => 'O1 (reasoning)'],
        ['id' => 'o1-pro', 'name' => 'O1 Pro (reasoning, premium)'],
    ];
}

/**
 * AJAX endpoint do odświeżania listy modeli
 */
add_action('wp_ajax_wp_ai_faq_refresh_models', 'wp_ai_faq_ajax_refresh_models');

function wp_ai_faq_ajax_refresh_models() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Brak uprawnień');
    }
    
    // Wyczyść cache
    delete_transient('wp_ai_faq_openai_models');
    
    // Pobierz świeżą listę
    $models = wp_ai_faq_get_openai_models();
    
    wp_send_json_success(['models' => $models]);
}


// Strona ustawień
function wp_ai_faq_settings_page() {
    if (isset($_POST['submit']) && wp_verify_nonce($_POST['_wpnonce'], 'wp_ai_faq_settings')) {
        error_log("FAQ DEBUG SAVE: Saving API key, length: " . strlen($_POST['wp_ai_faq_api_key']) . ", trimmed length: " . strlen(trim($_POST['wp_ai_faq_api_key'])));
        update_option('wp_ai_faq_api_key', trim($_POST['wp_ai_faq_api_key']));
        update_option('wp_ai_faq_model', sanitize_text_field($_POST['wp_ai_faq_model']));
        update_option('wp_ai_faq_prompt', wp_unslash(sanitize_textarea_field($_POST['wp_ai_faq_prompt'])));
        update_option('wp_ai_faq_language', sanitize_text_field($_POST['wp_ai_faq_language']));
        
        // Zapisz ustawienia typów postów
        $post_types_settings = [];
        if (isset($_POST['wp_ai_faq_post_types']) && is_array($_POST['wp_ai_faq_post_types'])) {
            foreach ($_POST['wp_ai_faq_post_types'] as $post_type => $settings) {
                $post_types_settings[sanitize_key($post_type)] = [
                    'enabled' => isset($settings['enabled']) && $settings['enabled'] == '1',
                    'display_mode' => isset($settings['display_mode']) ? sanitize_key($settings['display_mode']) : 'front_json'
                ];
            }
        }
        update_option('wp_ai_faq_post_types', $post_types_settings);
        
        echo '<div class="notice notice-success"><p>✅ Ustawienia zostały zapisane!</p></div>';
    }
    
    $api_key = get_option('wp_ai_faq_api_key', '');
    $model = get_option('wp_ai_faq_model', 'gpt-4o-mini');
    $prompt = get_option('wp_ai_faq_prompt', 'ZADANIE: Zwróć TYLKO poprawny JSON z FAQ (3-5 pytań i odpowiedzi).

FORMAT:
[{"question":"...","answer":"..."},{"question":"...","answer":"..."}]

ZASADY:
- TYLKO JSON w odpowiedzi
- Używaj podwójnych cudzysłowów
- Escapuj apostrofy: \"
- NIE używaj ```json```
- NIE dodawaj żadnego innego tekstu

TREŚĆ:
');    ?>
    <div class="wrap">
        <h1>🔧 Ustawienia AI FAQ</h1>        
        <form method="post">
            <?php wp_nonce_field('wp_ai_faq_settings'); ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">Klucz API OpenAI</th>
                    <td>
                        <input type="password" name="wp_ai_faq_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="sk-...">
                        <p class="description">Wprowadź swój klucz API OpenAI. Jeśli pozostawisz puste, użyty zostanie domyślny klucz.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Model AI</th>
                    <td>
                        <?php $available_models = wp_ai_faq_get_openai_models(); ?>
                        <select name="wp_ai_faq_model" id="wp_ai_faq_model" class="regular-text">
                            <?php foreach ($available_models as $m): ?>
                                <option value="<?php echo esc_attr($m['id']); ?>" <?php selected($model, $m['id']); ?>>
                                    <?php echo esc_html($m['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button type="button" id="refresh-models-btn" class="button button-small" style="margin-left: 10px;">
                            🔄 Odśwież listę
                        </button>
                        <span id="refresh-models-status" style="margin-left: 10px; display: none;"></span>
                        <p class="description">
                            Lista modeli jest pobierana automatycznie z API OpenAI i cache'owana na 24h.
                            <?php 
                            $cache_time = get_option('_transient_timeout_wp_ai_faq_openai_models');
                            if ($cache_time) {
                                $remaining = $cache_time - time();
                                if ($remaining > 0) {
                                    echo '<br><small>Cache wygaśnie za: ' . human_time_diff(time(), $cache_time) . '</small>';
                                }
                            }
                            ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Prompt AI</th>
                    <td>
                        <textarea name="wp_ai_faq_prompt" rows="4" class="large-text" placeholder="Wprowadź własny prompt..."><?php echo esc_textarea($prompt); ?></textarea>
                        <p class="description">Dostosuj prompt używany do generowania FAQ. Użyj <code>{LANGUAGE}</code> jako placeholder dla języka.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Język FAQ</th>
                    <td>
                        <?php $language = get_option('wp_ai_faq_language', 'auto'); ?>
                        <select name="wp_ai_faq_language" class="regular-text">
                            <option value="auto" <?php selected($language, 'auto'); ?>>🌐 Auto (wykryj z locale WP)</option>
                            <option value="auto_post" <?php selected($language, 'auto_post'); ?>>🔍 Auto z posta (WPML/Polylang)</option>
                            <optgroup label="Języki">
                                <option value="pl" <?php selected($language, 'pl'); ?>>🇵🇱 Polski</option>
                                <option value="en" <?php selected($language, 'en'); ?>>🇬🇧 Angielski</option>
                                <option value="de" <?php selected($language, 'de'); ?>>🇩🇪 Niemiecki</option>
                                <option value="fr" <?php selected($language, 'fr'); ?>>🇫🇷 Francuski</option>
                                <option value="es" <?php selected($language, 'es'); ?>>🇪🇸 Hiszpański</option>
                                <option value="it" <?php selected($language, 'it'); ?>>🇮🇹 Włoski</option>
                                <option value="cs" <?php selected($language, 'cs'); ?>>🇨🇿 Czeski</option>
                                <option value="uk" <?php selected($language, 'uk'); ?>>🇺🇦 Ukraiński</option>
                                <option value="nl" <?php selected($language, 'nl'); ?>>🇳🇱 Holenderski</option>
                                <option value="sv" <?php selected($language, 'sv'); ?>>🇸🇪 Szwedzki</option>
                            </optgroup>
                        </select>
                        <p class="description">
                            Wybierz język generowanego FAQ.<br>
                            <strong>Auto:</strong> używa locale WordPressa<br>
                            <strong>Auto z posta:</strong> wykrywa język z WPML/Polylang (dla multisite)
                        </p>
                    </td>
                </tr>
            </table>
            
            <h2 style="margin-top: 30px;">📄 Typy postów</h2>
            <p class="description">Wybierz na których typach postów ma być dostępne FAQ AI i w jakiej formie ma być wyświetlane.</p>
            
            <table class="form-table">
                <?php
                $post_types = get_post_types(['public' => true], 'objects');
                $saved_post_types = get_option('wp_ai_faq_post_types', [
                    'post' => ['enabled' => true, 'display_mode' => 'front_json'],
                    'page' => ['enabled' => true, 'display_mode' => 'front_json']
                ]);
                
                foreach ($post_types as $post_type): 
                    if ($post_type->name === 'attachment') continue; // Pomijamy załączniki
                    
                    $is_enabled = isset($saved_post_types[$post_type->name]['enabled']) && $saved_post_types[$post_type->name]['enabled'];
                    $display_mode = isset($saved_post_types[$post_type->name]['display_mode']) ? $saved_post_types[$post_type->name]['display_mode'] : 'front_json';
                ?>
                <tr>
                    <th scope="row">
                        <label>
                            <input type="checkbox" 
                                   name="wp_ai_faq_post_types[<?php echo esc_attr($post_type->name); ?>][enabled]" 
                                   value="1" 
                                   <?php checked($is_enabled); ?>>
                            <?php echo esc_html($post_type->labels->singular_name); ?>
                        </label>
                        <br><small style="color: #666;">(<?php echo esc_html($post_type->name); ?>)</small>
                    </th>
                    <td>
                        <select name="wp_ai_faq_post_types[<?php echo esc_attr($post_type->name); ?>][display_mode]" 
                                class="regular-text"
                                <?php echo !$is_enabled ? 'disabled' : ''; ?>>
                            <option value="front_json" <?php selected($display_mode, 'front_json'); ?>>
                                📄 Front + JSON-LD (widoczne FAQ + schema SEO)
                            </option>
                            <option value="json_only" <?php selected($display_mode, 'json_only'); ?>>
                                🔍 Tylko JSON-LD (niewidoczne, tylko schema SEO)
                            </option>
                        </select>
                        <p class="description" style="margin-top: 5px;">
                            <?php if ($display_mode === 'front_json'): ?>
                                FAQ będzie widoczne na stronie i dodane jako schema JSON-LD dla SEO.
                            <?php else: ?>
                                FAQ będzie niewidoczne dla użytkowników, ale dostępne dla wyszukiwarek jako JSON-LD.
                            <?php endif; ?>
                        </p>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Obsługa włączania/wyłączania selecta po zmianie checkboxa
                document.querySelectorAll('input[type="checkbox"][name^="wp_ai_faq_post_types"]').forEach(function(checkbox) {
                    checkbox.addEventListener('change', function() {
                        const row = this.closest('tr');
                        const select = row.querySelector('select');
                        if (select) {
                            select.disabled = !this.checked;
                        }
                    });
                });
                
                // Obsługa odświeżania listy modeli
                const refreshBtn = document.getElementById('refresh-models-btn');
                const modelSelect = document.getElementById('wp_ai_faq_model');
                const statusSpan = document.getElementById('refresh-models-status');
                
                if (refreshBtn) {
                    refreshBtn.addEventListener('click', function() {
                        refreshBtn.disabled = true;
                        refreshBtn.textContent = '⏳ Pobieranie...';
                        statusSpan.style.display = 'inline';
                        statusSpan.textContent = '';
                        
                        const currentValue = modelSelect.value;
                        
                        const data = new FormData();
                        data.append('action', 'wp_ai_faq_refresh_models');
                        
                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body: data
                        })
                        .then(response => response.json())
                        .then(result => {
                            if (result.success && result.data.models) {
                                // Aktualizuj select
                                modelSelect.innerHTML = '';
                                result.data.models.forEach(function(model) {
                                    const option = document.createElement('option');
                                    option.value = model.id;
                                    option.textContent = model.name;
                                    if (model.id === currentValue) {
                                        option.selected = true;
                                    }
                                    modelSelect.appendChild(option);
                                });
                                
                                statusSpan.innerHTML = '<span style="color: green;">✅ Lista zaktualizowana! (' + result.data.models.length + ' modeli)</span>';
                            } else {
                                statusSpan.innerHTML = '<span style="color: red;">❌ Błąd: ' + (result.data || 'Nie udało się pobrać modeli') + '</span>';
                            }
                        })
                        .catch(error => {
                            statusSpan.innerHTML = '<span style="color: red;">❌ Błąd połączenia</span>';
                        })
                        .finally(() => {
                            refreshBtn.disabled = false;
                            refreshBtn.textContent = '🔄 Odśwież listę';
                        });
                    });
                }
            });
            </script>
            
            <?php submit_button(); ?>
        </form>
        
        <div class="card">
            <h2>💡 Jak uzyskać klucz API OpenAI?</h2>
            <ol>
                <li>Wejdź na <a href="https://platform.openai.com/api-keys" target="_blank">https://platform.openai.com/api-keys</a></li>
                <li>Zaloguj się lub załóż konto</li>
                <li>Kliknij "Create new secret key"</li>
                <li>Skopiuj wygenerowany klucz i wklej go powyżej</li>
            </ol>
            <p><strong>Uwaga:</strong> Klucz API jest poufny - nie udostępniaj go nikomu!</p>
        </div>
        
        <div class="card">
            <h2>🤖 Informacje o modelach</h2>
            <ul>
                <li><strong>GPT-5.2 / GPT-5.2 Pro:</strong> Najnowsze modele (grudzień 2025) - najwyższa jakość</li>
                <li><strong>GPT-5.1 / GPT-5:</strong> Seria GPT-5 - świetna jakość, różne warianty (pro, mini, nano)</li>
                <li><strong>GPT-4.1:</strong> Ulepszona wersja GPT-4 (2025)</li>
                <li><strong>GPT-4o / GPT-4o Mini:</strong> Starsze modele - nadal dobra jakość, tańsze</li>
                <li><strong>GPT-3.5 Turbo:</strong> Legacy model - najtańszy, ale przestarzały</li>
                <li><strong>O3 / O1:</strong> Modele "reasoning" - lepsze w złożonych zadaniach logicznych</li>
            </ul>
            <p><small>💡 Lista modeli jest automatycznie pobierana z API OpenAI. Kliknij "Odśwież listę" aby zobaczyć najnowsze modele.</small></p>
        </div>
    </div>
    <?php
}
// Strona panelu administracyjnego
function wp_ai_faq_admin_page() {
    // Obsłuż akcje
    if (isset($_POST['generate_faq']) && wp_verify_nonce($_POST['_wpnonce'], 'bulk_faq_generate')) {
        wp_ai_faq_bulk_process();
    }
    
    if (isset($_POST['delete_faq']) && wp_verify_nonce($_POST['_wpnonce'], 'bulk_faq_generate')) {
        wp_ai_faq_bulk_delete();
    }
    
    // Pobierz włączone typy postów
    $post_types_settings = get_option('wp_ai_faq_post_types', [
        'post' => ['enabled' => true, 'display_mode' => 'front_json'],
        'page' => ['enabled' => true, 'display_mode' => 'front_json']
    ]);
    
    $enabled_post_types = [];
    foreach ($post_types_settings as $post_type => $settings) {
        if (isset($settings['enabled']) && $settings['enabled']) {
            $enabled_post_types[] = $post_type;
        }
    }
    
    // Jeśli brak włączonych typów, użyj domyślnych
    if (empty($enabled_post_types)) {
        $enabled_post_types = ['post', 'page'];
    }
    
    // Pobierz posty tylko z włączonych typów
    $posts = get_posts([
        'post_type' => $enabled_post_types,
        'post_status' => 'publish',
        'numberposts' => -1,
        'orderby' => 'date',
        'order' => 'DESC'
    ]);
    
    ?>
<div class="wrap">
        <h1>🤖 AI FAQ v1.6 + Edycja</h1>
        
        <div class="card">
            <h2>💡 Jak to działa</h2>
            <ul>
                <li><strong>Automatyczne:</strong> Nowe posty otrzymują FAQ automatycznie przy publikacji</li>
                <li><strong>Masowe:</strong> Zaznacz istniejące posty poniżej i wygeneruj FAQ hurtowo</li>
                <li><strong>Edycja:</strong> Kliknij "Edytuj FAQ" aby zmienić pytania i odpowiedzi</li>
                <li><strong>Wymagania:</strong> Post musi mieć minimum 300 znaków treści</li>
                <li><strong>Typy postów:</strong> W <a href="<?php echo admin_url('admin.php?page=wp-ai-faq-settings'); ?>">Ustawieniach</a> możesz wybrać na których typach postów ma działać FAQ</li>
                <li><strong>Tryby wyświetlania:</strong>
                    <ul style="margin-top: 5px; margin-left: 20px;">
                        <li>📄 <strong>Front + JSON-LD</strong> - FAQ widoczne na stronie + schema SEO</li>
                        <li>🔍 <strong>Tylko JSON-LD</strong> - FAQ niewidoczne dla użytkowników, tylko schema SEO dla wyszukiwarek</li>
                    </ul>
                </li>
            </ul>
        </div>
        
        <form method="post" id="bulk-faq-form">
            <?php wp_nonce_field('bulk_faq_generate'); ?>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <button type="submit" name="generate_faq" class="button button-primary">
                        🚀 Generuj FAQ dla zaznaczonych
                    </button>
                    <button type="submit" name="delete_faq" class="button button-secondary" onclick="return confirm('Czy na pewno usunąć FAQ?')">
                        🗑️ Usuń FAQ z zaznaczonych
                    </button>
                </div>
                <div class="alignright">
                    <label><input type="checkbox" id="select-all"> Zaznacz wszystkie</label>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <td class="manage-column column-cb check-column"><input type="checkbox"></td>
                        <th>Tytuł</th>
                        <th>Typ</th>
                        <th>Tryb wyświetlania</th>
                        <th>Status FAQ</th>
                        <th>Długość treści</th>
                        <th>Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($posts as $post): 
                        $content_length = strlen(strip_tags($post->post_content));
                        $has_faq = get_post_meta($post->ID, '_wp_ai_faq', true);
                        $faq_count = $has_faq && is_array($has_faq) && !isset($has_faq['error']) ? count($has_faq) : 0;
                        
                        // Pobierz tryb wyświetlania dla tego typu posta
                        $display_mode = isset($post_types_settings[$post->post_type]['display_mode']) 
                            ? $post_types_settings[$post->post_type]['display_mode'] 
                            : 'front_json';
                    ?>
                    <tr>
                        <th scope="row" class="check-column">
                            <input type="checkbox" name="post_ids[]" value="<?php echo $post->ID; ?>" <?php echo $content_length < 300 ? 'disabled' : ''; ?>>
                        </th>
                        <td>
                            <strong><a href="<?php echo get_edit_post_link($post->ID); ?>" target="_blank">
                                <?php echo esc_html($post->post_title); ?>
                            </a></strong>
                        </td>
                        <td><?php echo ucfirst($post->post_type); ?></td>
                        <td>
                            <?php if ($display_mode === 'json_only'): ?>
                                <span style="color: #0073aa;" title="Tylko JSON-LD dla SEO, niewidoczne na froncie">🔍 Tylko JSON-LD</span>
                            <?php else: ?>
                                <span style="color: #46b450;" title="Widoczne FAQ + JSON-LD dla SEO">📄 Front + JSON</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($has_faq && $faq_count > 0): ?>
                                ✅ <strong><?php echo $faq_count; ?> pytań</strong>
                            <?php elseif ($content_length < 300): ?>
                                ⚠️ Za krótki tekst
                            <?php else: ?>
                                ❌ Brak FAQ
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo number_format($content_length); ?> znaków
                            <?php if ($content_length < 300): ?>
                                <br><small style="color: orange;">Min. 300 znaków</small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($has_faq && $faq_count > 0): ?>
                                <button type="button" class="button button-small edit-faq-btn" 
                                        data-post-id="<?php echo $post->ID; ?>" 
                                        data-post-title="<?php echo esc_attr($post->post_title); ?>">
                                    ✏️ Edytuj FAQ
                                </button>
                            <?php else: ?>
                                <span style="color: #666;">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>
        
        <!-- Modal do edycji FAQ -->
        <div id="edit-faq-modal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.7); z-index: 999999;">
            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 20px; border-radius: 8px; max-width: 800px; width: 90%; max-height: 80%; overflow-y: auto;">
                <div class="faq-modal-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #ddd; padding-bottom: 10px;">
                    <h2 style="margin: 0;">✏️ Edytuj FAQ: <span id="modal-post-title"></span></h2>
                    <button type="button" id="close-faq-modal" style="background: none; border: none; font-size: 24px; cursor: pointer; line-height: 1;">&times;</button>
                </div>
                
                <div id="faq-modal-content">
                    <p>Ładowanie...</p>
                </div>
                
                <div class="faq-modal-footer" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd; text-align: right;">
                    <button type="button" id="add-faq-item" class="button" style="float: left;">➕ Dodaj pytanie</button>
                    <button type="button" id="cancel-faq-edit" class="button">Anuluj</button>
                    <button type="button" id="save-faq-edit" class="button button-primary" style="margin-left: 10px;">💾 Zapisz zmiany</button>
                </div>
            </div>
        </div>
    </div>
    

<script>document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('bulk-faq-form');
    const selectAll = document.getElementById('select-all');
    const checkboxes = document.querySelectorAll('input[name="post_ids[]"]:not([disabled])');
    const generateBtn = document.querySelector('button[name="generate_faq"]');
    const modal = document.getElementById('edit-faq-modal');
    
    // Obsługa zaznaczania wszystkich
    selectAll.addEventListener('change', function() {
        checkboxes.forEach(cb => cb.checked = this.checked);
    });
    
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            selectAll.checked = allChecked;
        });
    });
    
    // Obsługa edycji FAQ
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('edit-faq-btn')) {
            const postId = e.target.getAttribute('data-post-id');
            const postTitle = e.target.getAttribute('data-post-title');
            openEditModal(postId, postTitle);
        }
    });
    
    // Zamykanie modala
    document.getElementById('close-faq-modal').addEventListener('click', closeEditModal);
    document.getElementById('cancel-faq-edit').addEventListener('click', closeEditModal);
    
    // Kliknięcie w tło modala
    modal.addEventListener('click', function(e) {
        if (e.target === modal) closeEditModal();
    });
    
    // Dodawanie nowego pytania
    document.getElementById('add-faq-item').addEventListener('click', function() {
        addNewFaqItem();
    });
    
    // Zapisywanie zmian
    document.getElementById('save-faq-edit').addEventListener('click', function() {
        saveFaqChanges();
    });
    
    function openEditModal(postId, postTitle) {
        document.getElementById('modal-post-title').textContent = postTitle;
        modal.style.display = 'block';
        
        // Pobierz FAQ dla posta
        const data = new FormData();
        data.append('action', 'wp_ai_faq_get_faq');
        data.append('nonce', wpAiFaq.nonce);
        data.append('post_id', postId);
        
        fetch(wpAiFaq.ajax_url, {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(result => {
                if (result.success) {
                renderFaqEditor(result.data.faq, postId);
            } else {
                document.getElementById('faq-modal-content').innerHTML = '<p style="color: red;">Błąd: ' + (result.data || 'Nie udało się pobrać FAQ') + '</p>';
            }
        })
        .catch(error => {
            document.getElementById('faq-modal-content').innerHTML = '<p style="color: red;">Błąd połączenia: ' + error.message + '</p>';
        });
    }
    
    function closeEditModal() {
        modal.style.display = 'none';
    }
    
    function renderFaqEditor(faqData, postId) {
        let html = '<div id="faq-editor" data-post-id="' + postId + '">';
        
        if (faqData && faqData.length > 0) {
            faqData.forEach((item, index) => {
                html += renderFaqItem(item, index);
            });
        } else {
            html += '<p>Brak FAQ do edycji.</p>';
        }
        
        html += '</div>';
        document.getElementById('faq-modal-content').innerHTML = html;
        
        // Dodaj obsługę usuwania
        document.querySelectorAll('.remove-faq-item').forEach(btn => {
            btn.addEventListener('click', function() {
                if (confirm('Czy na pewno usunąć to pytanie?')) {
                    this.closest('.faq-edit-item').remove();
                }
            });
        });
    }
    
    function renderFaqItem(item, index) {
        return `
            <div class="faq-edit-item" style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 5px; background: #f9f9f9;">
                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Pytanie ${index + 1}:</label>
                    <input type="text" class="faq-question" value="${escapeHtml(item.question)}" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px;">
                </div>
                <div style="margin-bottom: 10px;">
                    <label style="display: block; font-weight: bold; margin-bottom: 5px;">Odpowiedź:</label>
                    <textarea class="faq-answer" rows="3" style="width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 3px;">${escapeHtml(item.answer)}</textarea>
                </div>
                <button type="button" class="button button-small remove-faq-item" style="color: #a00;">🗑️ Usuń</button>
            </div>
        `;
    }
    
    function addNewFaqItem() {
        const editor = document.getElementById('faq-editor');
        const newItem = { question: '', answer: '' };
        const index = editor.querySelectorAll('.faq-edit-item').length;
        
        const itemHtml = renderFaqItem(newItem, index);
        editor.insertAdjacentHTML('beforeend', itemHtml);
        
        // Dodaj obsługę usuwania dla nowego elementu
        const newItemElement = editor.lastElementChild;
        newItemElement.querySelector('.remove-faq-item').addEventListener('click', function() {
            if (confirm('Czy na pewno usunąć to pytanie?')) {
                this.closest('.faq-edit-item').remove();
            }
        });
    }
    
    function saveFaqChanges() {
        const editor = document.getElementById('faq-editor');
        const postId = editor.getAttribute('data-post-id');
        const items = editor.querySelectorAll('.faq-edit-item');
        
        const faqData = [];
        items.forEach(item => {
            const question = item.querySelector('.faq-question').value.trim();
            const answer = item.querySelector('.faq-answer').value.trim();
            
            if (question && answer) {
                faqData.push({ question: question, answer: answer });
            }
        });
        
        if (faqData.length === 0) {
            alert('Dodaj przynajmniej jedno pytanie i odpowiedź.');
            return;
        }
        
        // Zapisz zmiany
        const data = new FormData();
        data.append('action', 'wp_ai_faq_save_faq');
        data.append('nonce', wpAiFaq.nonce);
        data.append('post_id', postId);
        data.append('faq_data', JSON.stringify(faqData));
        
        // Wyłącz przycisk podczas zapisywania
        const saveBtn = document.getElementById('save-faq-edit');
        saveBtn.disabled = true;
        saveBtn.textContent = 'Zapisywanie...';
        
        fetch(wpAiFaq.ajax_url, {
            method: 'POST',
            body: data
        })
        .then(response => response.json())
        .then(result => {
                if (result.success) {
                alert('FAQ zostało zapisane!');
                closeEditModal();
                location.reload(); // Odśwież stronę żeby zobaczyć zmiany
            } else {
                alert('Błąd: ' + (result.data || 'Nie udało się zapisać FAQ'));
            }
        })
        .catch(error => {
            alert('Błąd połączenia: ' + error.message);
        })
        .finally(() => {
            saveBtn.disabled = false;
            saveBtn.textContent = '💾 Zapisz zmiany';
        });
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // AJAX generowanie FAQ (stary kod)
    form.addEventListener('submit', function(e) {
        if (e.submitter && e.submitter.name === 'generate_faq') {
            e.preventDefault();
            
            const checked = document.querySelectorAll('input[name="post_ids[]"]:checked');
            if (checked.length === 0) {
                alert('Proszę zaznaczyć przynajmniej jeden post.');
                return;
            }
            
            const postIds = Array.from(checked).map(cb => cb.value);
            generateFAQWithProgress(postIds);
        }
    });
    
    function generateFAQWithProgress(postIds) {
        showProgressUI();
        generateBtn.disabled = true;
        
        let current = 0;
        let generated = 0;
        let errors = 0;
        let skipped = 0;
        
        function processNextPost() {
            if (current >= postIds.length) {
                const progressText = document.getElementById('progress-text');
                if (progressText) {
                    progressText.innerHTML = '<strong style="color: green;">✅ Zakończono!</strong> Gotowe! Sprawdź wyniki poniżej.';
                }
                setTimeout(() => location.reload(), 2000);
                return;
            }
            
            const postId = postIds[current];
            current++;
            
            const data = new FormData();
            data.append('action', 'wp_ai_faq_bulk_generate');
            data.append('nonce', wpAiFaq.nonce);
            data.append('post_id', postId);
            data.append('current', current);
            data.append('total', postIds.length);            
            fetch(wpAiFaq.ajax_url, {
                method: 'POST',
                body: data
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    
                    if (result.data.status === 'success') generated++;
                    else if (result.data.status === 'skipped') skipped++;
                    
                    else if (result.data.status === 'error') errors++;
                    
                    updateProgress({
                        progress: result.data.progress,
                        current: result.data.current,
                        total: result.data.total,
                        generated: generated,
                        errors: errors,
                        skipped: skipped,
                        current_post: result.data.title,
                        latest_result: result.data
                    });
                    
                    setTimeout(processNextPost, 1000);
                } else {
                    showError(result.data || 'Błąd podczas generowania FAQ');
                    generateBtn.disabled = false;
                }
            })
            .catch(error => {
                showError('Błąd połączenia: ' + error.message);
                generateBtn.disabled = false;
            });
        }
        
        processNextPost();
    }
    
    function showProgressUI() {
        const progressHTML = `
            <div id="faq-progress" style="margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 5px;">
                <h3>🚀 Generowanie FAQ w toku...</h3>
                <div style="background: #ddd; border-radius: 10px; overflow: hidden; margin: 10px 0;">
                    <div id="progress-bar" style="height: 20px; background: #0073aa; width: 0%; transition: width 0.3s;"></div>
                </div>
                <div id="progress-text">Przygotowywanie...</div>
                <div id="progress-stats"></div>
                <div id="progress-details" style="max-height: 200px; overflow-y: auto; margin-top: 10px;"></div>
            </div>
        `;
        
        const existingProgress = document.getElementById('faq-progress');
        if (existingProgress) existingProgress.remove();
        
        form.insertAdjacentHTML('afterend', progressHTML);
    }
    
    function updateProgress(data) {
        const progressBar = document.getElementById('progress-bar');
        const progressText = document.getElementById('progress-text');
        const progressStats = document.getElementById('progress-stats');
        const progressDetails = document.getElementById('progress-details');
        
        if (progressBar) progressBar.style.width = data.progress + '%';
        
        if (progressText) {
            progressText.innerHTML = `Post ${data.current}/${data.total}: <strong>${data.current_post}</strong>`;
        }
        
        if (progressStats) {
            progressStats.innerHTML = `
                ✅ Wygenerowano: ${data.generated} | 
                ❌ Błędy: ${data.errors} | 
                ⏭️ Pominięte: ${data.skipped}
            `;
        }
        
        if (progressDetails && data.latest_result) {
            const latest = data.latest_result;
            if (latest) {
                const statusIcon = latest.status === 'success' ? '✅' : 
                                 latest.status === 'error' ? '❌' : '⏭️';
                progressDetails.innerHTML += `
                    <div style="padding: 2px 0;">
                        ${statusIcon} <strong>${latest.title}</strong>: ${latest.message}
                    </div>
                `;
                progressDetails.scrollTop = progressDetails.scrollHeight;
            }
        }
    }
    
    function showError(message) {
        const errorHTML = `
            <div class="notice notice-error" style="margin: 20px 0;">
                <p>❌ <strong>Błąd:</strong> ${message}</p>
            </div>
        `;
        form.insertAdjacentHTML('afterend', errorHTML);
    }
});
</script>
    <?php
}

// Proces masowego generowania
function wp_ai_faq_bulk_process() {
    if (!isset($_POST['post_ids']) || !is_array($_POST['post_ids'])) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>❌ Nie wybrano postów.</p></div>';
        });
        return;
    }
    
    $post_ids = array_map('intval', $_POST['post_ids']);
    $generated = 0;
    $errors = 0;
    $skipped = 0;
    
    foreach ($post_ids as $post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            $skipped++;
            continue;
        }
        
        $existing_faq = get_post_meta($post_id, '_wp_ai_faq', true);
        if ($existing_faq) {
            $skipped++;
            continue;
        }
        
        $content = strip_tags($post->post_content);
        if (strlen($content) < 300) {
            $skipped++;
            continue;
        }
        
        $faq = wp_ai_faq_call_openai($content, $post_id);
        if ($faq && is_array($faq) && !isset($faq['error'])) {
            update_post_meta($post_id, '_wp_ai_faq', $faq);
            $generated++;
        } else {
            $errors++;
        }
        
        sleep(1);
    }
    
    add_action('admin_notices', function() use ($generated, $errors, $skipped) {
        $class = $errors > 0 ? 'notice-warning' : 'notice-success';
        echo "<div class='notice $class'><p>";
        echo "✅ <strong>Gotowe!</strong> Wygenerowano: $generated, Błędy: $errors, Pominięte: $skipped";
        echo "</p></div>";
    });
}

// Proces usuwania FAQ
function wp_ai_faq_bulk_delete() {
    if (!isset($_POST['post_ids']) || !is_array($_POST['post_ids'])) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p>❌ Nie wybrano postów.</p></div>';
        });
        return;
    }
    
    $post_ids = array_map('intval', $_POST['post_ids']);
    $deleted = 0;
    
    foreach ($post_ids as $post_id) {
        $existing_faq = get_post_meta($post_id, '_wp_ai_faq', true);
        if ($existing_faq) {
            delete_post_meta($post_id, '_wp_ai_faq');
            $deleted++;
        }
    }
    
    add_action('admin_notices', function() use ($deleted) {
        echo "<div class='notice notice-success'><p>🗑️ Usunięto FAQ z $deleted postów.</p></div>";
    });
}

// ===== SCRIPTS AND AJAX =====

// Enqueue admin scripts and localize AJAX data
add_action('admin_enqueue_scripts', 'wp_ai_faq_admin_scripts');

function wp_ai_faq_admin_scripts($hook) {
    // Only load on our plugin pages
    if (strpos($hook, 'wp-ai-faq') === false) {
        return;
    }
    
    // Localize script for AJAX
    wp_add_inline_script('jquery', '
        var wpAiFaq = {
            ajax_url: "' . admin_url('admin-ajax.php') . '",
            nonce: "' . wp_create_nonce('bulk_faq_generate') . '"
        };
    ');
}


// Rejestruj AJAX endpoints
add_action('wp_ajax_wp_ai_faq_bulk_generate', 'wp_ai_faq_ajax_bulk_generate');
add_action('wp_ajax_wp_ai_faq_get_faq', 'wp_ai_faq_ajax_get_faq');
add_action('wp_ajax_wp_ai_faq_save_faq', 'wp_ai_faq_ajax_save_faq');

function wp_ai_faq_ajax_bulk_generate() {
    error_log("FAQ DEBUG: AJAX bulk generate called");
    
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'bulk_faq_generate')) {
        wp_die('Brak uprawnień');
    }
    
    $post_id = isset($_POST["post_id"]) ? intval($_POST["post_id"]) : 0;
    $current = isset($_POST["current"]) ? intval($_POST["current"]) : 1;
    error_log("FAQ DEBUG: Processing post ID: " . $post_id);
    $total = isset($_POST["total"]) ? intval($_POST["total"]) : 1;
    
    if (!$post_id) {
        wp_send_json_error("Nie wybrano posta");
    }
    
    $post = get_post($post_id);
    $post_title = $post ? $post->post_title : "Post #$post_id";
    
    if (!$post || $post->post_status !== 'publish') {
        wp_send_json_success([
            'post_id' => $post_id,
            'title' => $post_title,
            'status' => 'skipped',
            'message' => 'Post nieaktywny',
            'current' => $current,
            'total' => $total,
            'progress' => round(($current / $total) * 100)
        ]);
    }
    
    $existing_faq = get_post_meta($post_id, '_wp_ai_faq', true);
    if ($existing_faq) {
        wp_send_json_success([
            'post_id' => $post_id,
            'title' => $post_title,
            'status' => 'skipped',
            'message' => 'FAQ już istnieje',
            'current' => $current,
            'total' => $total,
            'progress' => round(($current / $total) * 100)
        ]);
    }
    
    $content = strip_tags($post->post_content);
    if (strlen($content) < 300) {
        wp_send_json_success([
            'post_id' => $post_id,
            'title' => $post_title,
            'status' => 'skipped',
            'message' => 'Za krótka treść (< 300 znaków)',
            'current' => $current,
            'total' => $total,
            'progress' => round(($current / $total) * 100)
        ]);
    }
    
    error_log("FAQ DEBUG: About to call OpenAI with content length: " . strlen($content));
    $faq = wp_ai_faq_call_openai($content, $post_id);
    if ($faq && is_array($faq) && !isset($faq['error'])) {
        update_post_meta($post_id, '_wp_ai_faq', $faq);
    error_log("FAQ DEBUG: OpenAI returned: " . ($faq ? "SUCCESS (" . count($faq) . " items)" : "FAILED"));
        wp_send_json_success([
            'post_id' => $post_id,
            'title' => $post_title,
            'status' => 'success',
            'message' => count($faq) . ' pytań wygenerowano',
            'current' => $current,
            'total' => $total,
            'progress' => round(($current / $total) * 100)
        ]);
    } else {
        wp_send_json_success([
            'post_id' => $post_id,
            'title' => $post_title,
            'status' => 'error',
            'message' => (is_array($faq) && isset($faq['error'])) ? $faq['error'] : 'Błąd generowania FAQ',
            'current' => $current,
            'total' => $total,
            'progress' => round(($current / $total) * 100)
        ]);
    }
}

// Nowy endpoint do pobierania FAQ
function wp_ai_faq_ajax_get_faq() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'bulk_faq_generate')) {
        wp_send_json_error('Brak uprawnień');
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error('Nieprawidłowy ID posta');
    }
    
    $faq = get_post_meta($post_id, '_wp_ai_faq', true);
    if (!$faq || !is_array($faq)) {
        wp_send_json_error('Brak FAQ dla tego posta');
    }
    
    wp_send_json_success(['faq' => $faq]);
}

// Nowy endpoint do zapisywania FAQ
function wp_ai_faq_ajax_save_faq() {
    if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['nonce'], 'bulk_faq_generate')) {
        wp_send_json_error('Brak uprawnień');
    }
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if (!$post_id) {
        wp_send_json_error('Nieprawidłowy ID posta');
    }
    
    $faq_data = isset($_POST['faq_data']) ? $_POST['faq_data'] : '';
    if (empty($faq_data)) {
        wp_send_json_error('Brak danych FAQ');
    }
    
    $faq = json_decode(stripslashes($faq_data), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        wp_send_json_error('Nieprawidłowe dane JSON');
    }
    
    if (!is_array($faq) || empty($faq)) {
        wp_send_json_error('FAQ musi zawierać przynajmniej jedno pytanie');
    }
    
    // Walidacja danych
    foreach ($faq as $item) {
        if (!isset($item['question']) || !isset($item['answer']) || 
            empty(trim($item['question'])) || empty(trim($item['answer']))) {
            wp_send_json_error('Każde pytanie musi mieć treść i odpowiedź');
        }
    }
    
    // Zapisz FAQ
    $result = update_post_meta($post_id, '_wp_ai_faq', $faq);
    if ($result !== false) {
        wp_send_json_success('FAQ zostało zapisane');
    } else {
        wp_send_json_error('Błąd podczas zapisywania FAQ');
    }
}
