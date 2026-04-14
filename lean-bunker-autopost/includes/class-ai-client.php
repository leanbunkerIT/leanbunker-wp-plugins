<?php
/**
 * Lean_AI_Client
 *
 * Thin wrapper around the Together AI chat-completions endpoint.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class Lean_AI_Client {

    private string $api_key;
    private string $model;

    public function __construct( string $api_key, string $model ) {
        $this->api_key = $api_key;
        $this->model   = $model;
    }

    /**
     * Send a chat-completion request and return the assistant message.
     *
     * @param string $system      System prompt.
     * @param string $user        User message.
     * @param float  $temperature Sampling temperature (0–1).
     * @return string             Generated text, or empty string on error.
     */
    public function call( string $system, string $user, float $temperature = 0.3 ): string {
        if ( empty( $this->api_key ) ) return '';

        $response = wp_remote_post( 'https://api.together.ai/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => $this->model,
                'messages'    => [
                    [ 'role' => 'system', 'content' => $system ],
                    [ 'role' => 'user',   'content' => $user ],
                ],
                'temperature' => $temperature,
            ] ),
            'timeout' => 45,
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( '[Lean Autopost] AI Error: ' . $response->get_error_message() );
            return '';
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return $data['choices'][0]['message']['content'] ?? '';
    }
}
