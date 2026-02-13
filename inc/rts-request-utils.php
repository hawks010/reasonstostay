<?php
/**
 * RTS Request Utilities
 *
 * Shared helpers for extracting and verifying request nonces.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('rts_sanitize_unslashed_scalar')) {
    function rts_sanitize_unslashed_scalar($value): string {
        if (is_array($value) || is_object($value)) {
            return '';
        }

        return sanitize_text_field(wp_unslash((string) $value));
    }
}

if (!function_exists('rts_verify_nonce_actions')) {
    /**
     * Verify a nonce against multiple accepted actions.
     */
    function rts_verify_nonce_actions(string $nonce, array $actions): bool {
        $nonce = trim($nonce);
        if ($nonce === '') {
            return false;
        }

        foreach ($actions as $action) {
            if (!is_string($action) || $action === '') {
                continue;
            }

            $result = wp_verify_nonce($nonce, $action);
            if ($result === 1 || $result === 2) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('rts_request_nonce_from_array')) {
    /**
     * Extract and sanitize a nonce from an array-like source.
     */
    function rts_request_nonce_from_array(array $source, array $keys = ['nonce', '_wpnonce', 'rts_token']): string {
        foreach ($keys as $key) {
            if (!isset($source[$key])) {
                continue;
            }

            $nonce = rts_sanitize_unslashed_scalar($source[$key]);
            if ($nonce !== '') {
                return $nonce;
            }
        }

        return '';
    }
}

if (!function_exists('rts_rest_request_nonce')) {
    /**
     * Extract and sanitize a nonce from REST headers, params, or JSON body.
     */
    function rts_rest_request_nonce(\WP_REST_Request $request, array $param_keys = ['nonce', '_wpnonce', 'rts_token'], array $header_keys = ['x_wp_nonce', 'x-wp-nonce']): string {
        foreach ($header_keys as $header_key) {
            $header_nonce = $request->get_header($header_key);
            if (!is_string($header_nonce) || $header_nonce === '') {
                continue;
            }

            $nonce = sanitize_text_field($header_nonce);
            if ($nonce !== '') {
                return $nonce;
            }
        }

        foreach ($param_keys as $param_key) {
            $param_nonce = $request->get_param($param_key);
            if ($param_nonce === null) {
                continue;
            }

            $nonce = sanitize_text_field((string) $param_nonce);
            if ($nonce !== '') {
                return $nonce;
            }
        }

        $json = $request->get_json_params();
        if (is_array($json)) {
            return rts_request_nonce_from_array($json, $param_keys);
        }

        return '';
    }
}

if (!function_exists('rts_rest_public_read_permission')) {
    /**
     * Explicit policy for intentionally public read-only REST endpoints.
     */
    function rts_rest_public_read_permission(\WP_REST_Request $request): bool {
        $method = strtoupper((string) $request->get_method());
        return in_array($method, ['GET', 'OPTIONS'], true);
    }
}
