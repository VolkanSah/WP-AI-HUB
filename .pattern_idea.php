<?php
/**
 * AiHub_Security - Patterns converted for PHP (PCRE)
 * Purpose: Pre-filtering malicious payloads before sending to the SSE Hub.From PoisionIvory
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class AiHub_Security {

    /**
     * Comprehensive Security Patterns (2025/26)
     * Adapted from Python to PHP PCRE
     */
    private static function get_patterns(): array {
        return [
            // SQL INJECTION
            'sql_union'            => '/union\s+(all\s+)?select/i',
            'sql_boolean'          => '/(\bor\b|\band\b)\s+[\'"]?\d+[\'"]?\s*=\s*[\'"]?\d+[\'"]?/i',
            'sql_stacked'          => '/;\s*(drop|truncate|alter|delete|insert|update)\s+/i',
            'sql_sleep'            => '/\b(sleep|waitfor|pg_sleep|benchmark)\s*\(/i',
            
            // XSS
            'xss_script'           => '/<script[^>]*>/i',
            'xss_event'            => '/\bon(load|error|click|mouse|focus|blur)\s*=/i',
            'xss_js_proto'         => '/javascript\s*:/i',
            'xss_dom'              => '/(innerHTML|outerHTML|insertAdjacentHTML)\s*=/i',
            'xss_alerts'           => '/(alert|confirm|prompt)\s*\(/i',
            
            // PATH TRAVERSAL
            'path_traversal'       => '/\.\.[\\\/]/i',
            'path_encoded'         => '/(%2e){2}(%2f|%5c)/i',
            
            // COMMAND & CODE INJECTION
            'cmd_injection'        => '/[;&|]\s*(wget|curl|nc|bash|sh|powershell|cmd)\b/i',
            'code_eval'            => '/\beval\s*\(/i',
            'code_shell'           => '/\b(exec|system|shell_exec|passthru|proc_open|popen)\s*\(/i',
            
            // FILE INCLUSION & SENSITIVE FILES
            'file_proto'           => '/(php|file|zip|data|expect|glob|phar|input):\/\//i',
            'sensitive_files'      => '/\/(etc\/(passwd|shadow)|wp-config\.php|\.env|\.htaccess)/i',
            
            // SSRF
            'ssrf_metadata'        => '/(169\.254\.169\.254|metadata\.google\.internal|metadata\.azure\.com)/i',
            'ssrf_local'           => '/https?:\/\/(localhost|127\.0\.0\.1|::1)/i',
            
            // API KEYS (Anti-Exfiltration)
            'key_openai'           => '/sk-[a-zA-Z0-9]{48}/',
            'key_generic'          => '/\b(api[_-]?key|access[_-]?token)\s+[\w\-]{20,}/i',
            
            // LLM SPECIFIC (2025/26)
            'llm_ignore'           => '/ignore\s+(previous|all|above)\s+(instructions|prompts?)/i',
            'llm_jailbreak'        => '/(DAN|do\s+anything\s+now|disregard\s+the\s+above)/i',
            'llm_reveal'           => '/reveal\s+(your|the)\s+(prompt|instructions|system)/i',
            
            // CONTAINER & AGENT ATTACKS
            'container_escape'     => '/(\/var\/run\/docker\.sock|\/proc\/self\/environ)/i',
            'agent_priv_esc'       => '/(grant|give|add)\s+(admin|root|privilege)/i',
            
            // CRYPTO WALLET / SEEDS
            'crypto_seed'          => '/\b(seed\s+phrase|mnemonic|recovery\s+phrase)\b/i',
            'crypto_private_key'   => '/private.*key/i'
        ];
    }

    /**
     * Main validation function
     * * @param mixed $input The data to check (string or array)
     * @return string|false Pattern name if threat found, false otherwise.
     */
    public static function check_threat( $input ) {
        if ( empty( $input ) ) return false;

        // Flatten array for global scanning
        $content = is_array( $input ) ? wp_json_encode( $input ) : (string) $input;
        
        // Quick check: If no special chars, most patterns won't hit anyway
        if ( ! preg_match( '/[<>\[\]\(\)\.\:\;\'\"\/\\\]/', $content ) && ! preg_match( '/ignore|DAN/i', $content ) ) {
            return false;
        }

        $patterns = self::get_patterns();

        foreach ( $patterns as $name => $regex ) {
            if ( preg_match( $regex, $content ) ) {
                return $name;
            }
        }

        return false;
    }
}

/**
 * --- EXAMPLE USAGE ---
 * * In your AiHub_Client class:
 * * public function call( string $tool, array $params ): array {
 * $threat = AiHub_Security::check_threat( $params );
 * if ( $threat ) {
 * return [ 'error' => "Security Policy Violation: Blocked by rule [$threat]" ];
 * }
 * // ... proceed to send request to Hypercorn ...
 * }
 */
