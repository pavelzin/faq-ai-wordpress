<?php
/*
Plugin Name: WP AI FAQ
Description: Automatycznie generuje FAQ z treści postów używając OpenAI API i dodaje je jako JSON-LD structured data dla lepszego SEO.
Version: 1.0.0
Author: Paweł Żin
Author URI: https://github.com/pavelzin
Plugin URI: https://github.com/pavelzin/faq-ai-wordpress
Text Domain: wp-ai-faq
Domain Path: /languages
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) {
    exit;
}

// Hook do generowania FAQ przy zapisywaniu posta
add_action('save_post', 'wp_ai_faq_generate', 20, 2);

/**
 * Generuje FAQ dla posta po jego zapisaniu/opublikowaniu
 *
 * @param int $post_id ID posta
 * @param WP_Post $post Obiekt posta
 */
function wp_ai_faq_generate($post_id, $post) {
    // Pomiń rewizje i niepublikowane posty
    if (wp_is_post_revision($post_id) || $post->post_status !== 'publish') {
        return;
    }

    // Sprawdź czy FAQ już istnieje
    $existing_faq = get_post_meta($post_id, '_wp_ai_faq', true);
    if ($existing_faq) {
        return;
    }

    // Usuń HTML tagi z treści
    $content = strip_tags($post->post_content);
    
    // Nie generuj FAQ dla krótkich postów (mniej niż 300 znaków)
    if (strlen($content) < 300) {
        return;
    }

    // Wywołaj OpenAI API
    $faq = wp_ai_faq_call_openai($content);
    if (!$faq) {
        return;
    }

    // Zapisz FAQ do meta pola posta
    update_post_meta($post_id, '_wp_ai_faq', $faq);
}

/**
 * Wywołuje OpenAI API żeby wygenerować FAQ
 *
 * @param string $content Treść posta
 * @return array|false Array z FAQ lub false przy błędzie
 */
function wp_ai_faq_call_openai($content) {
    // WAŻNE: Dodaj tutaj swój klucz API OpenAI
    $api_key = get_option('wp_ai_faq_api_key', '');
    
    if (empty($api_key)) {
        error_log('WP AI FAQ: Brak klucza API OpenAI. Ustaw go w ustawieniach wtyczki.');
        return false;
    }

    $prompt = "Na podstawie tego tekstu stwórz 3-5 pytań i odpowiedzi w formacie JSON [{\"question\":\"...\",\"answer\":\"...\"}]. Odpowiadaj w języku polskim:\n\n" . $content;

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt]],
            'max_tokens' => 500,
        ]),
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('WP AI FAQ: HTTP Error - ' . $response->get_error_message());
        return false;
    }

    $response_code = wp_remote_retrieve_response_code($response);
    if ($response_code !== 200) {
        error_log('WP AI FAQ: API Error - HTTP ' . $response_code);
        return false;
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);
    if (!$body) {
        error_log('WP AI FAQ: Failed to decode API response');
        return false;
    }
    
    $raw = $body['choices'][0]['message']['content'] ?? '';

    // Wyciągnij JSON z bloku markdown jeśli jest obecny
    if (preg_match('/```json\s*(.*?)\s*```/s', $raw, $matches)) {
        $raw = $matches[1];
    }

    $faq = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('WP AI FAQ: JSON decode error - ' . json_last_error_msg());
        return false;
    }
    
    return is_array($faq) ? $faq : false;
}

// Hook do wyświetlania FAQ na frontendzie
add_filter('the_content', 'wp_ai_faq_display');

/**
 * Wyświetla FAQ na pojedynczych postach
 *
 * @param string $content Treść posta
 * @return string Treść z dodanym FAQ
 */
function wp_ai_faq_display($content) {
    if (is_single()) {
        global $post;
        $faq = get_post_meta($post->ID, '_wp_ai_faq', true);
        
        if ($faq && is_array($faq)) {
            $faq_html = '<div class="wp-ai-faq"><h2>FAQ</h2>';
            $json_ld = [
                '@context' => 'https://schema.org',
                '@type' => 'FAQPage',
                'mainEntity' => []
            ];

            foreach ($faq as $item) {
                $question = esc_html($item['question'] ?? '');
                $answer = esc_html($item['answer'] ?? '');
                
                if (!empty($question) && !empty($answer)) {
                    $faq_html .= "<div class='faq-item'><strong>{$question}</strong><p>{$answer}</p></div>";

                    $json_ld['mainEntity'][] = [
                        '@type' => 'Question',
                        'name' => $question,
                        'acceptedAnswer' => [
                            '@type' => 'Answer', 
                            'text' => $answer
                        ]
                    ];
                }
            }

            // Dodaj JSON-LD structured data dla SEO
            $faq_html .= '<script type="application/ld+json">' . json_encode($json_ld, JSON_UNESCAPED_UNICODE) . '</script>';
            $faq_html .= '</div>';
            $content .= $faq_html;
        }
    }
    return $content;
}

// Hook do dodania menu w panelu admina
add_action('admin_menu', 'wp_ai_faq_admin_menu');

/**
 * Dodaje menu wtyczki w panelu administracyjnym
 */
function wp_ai_faq_admin_menu() {
    add_options_page(
        'WP AI FAQ Settings',
        'WP AI FAQ',
        'manage_options',
        'wp-ai-faq',
        'wp_ai_faq_settings_page'
    );
}

/**
 * Renderuje stronę ustawień wtyczki
 */
function wp_ai_faq_settings_page() {
    if (isset($_POST['submit'])) {
        update_option('wp_ai_faq_api_key', sanitize_text_field($_POST['api_key']));
        echo '<div class="notice notice-success"><p>Ustawienia zostały zapisane!</p></div>';
    }
    
    $api_key = get_option('wp_ai_faq_api_key', '');
    ?>
    <div class="wrap">
        <h1>WP AI FAQ - Ustawienia</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row">Klucz API OpenAI</th>
                    <td>
                        <input type="text" name="api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" />
                        <p class="description">
                            Wprowadź swój klucz API OpenAI. Możesz go uzyskać na: 
                            <a href="https://platform.openai.com/api-keys" target="_blank">https://platform.openai.com/api-keys</a>
                        </p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <h2>Jak używać</h2>
        <ol>
            <li>Wprowadź klucz API OpenAI powyżej</li>
            <li>Opublikuj nowy post z treścią dłuższą niż 300 znaków</li>
            <li>FAQ zostanie automatycznie wygenerowane i wyświetlone na dole posta</li>
            <li>FAQ zawiera structured data (JSON-LD) dla lepszego SEO</li>
        </ol>
    </div>
    <?php
}
