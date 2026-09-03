<?php
/**
 * Provider adapters.
 *
 * Four providers with four wire formats. A mistake here fails as "HTTP 400"
 * against a live key, which is an expensive and confusing way to find out, so
 * the shapes are pinned.
 */

use PHPUnit\Framework\TestCase;

class AiProviderTest extends TestCase {

	private function messages() {
		return array(
			array(
				'role'    => 'system',
				'content' => 'You are an editor.',
			),
			array(
				'role'    => 'user',
				'content' => 'Rewrite this.',
			),
		);
	}

	private function body( $slug, $options = array() ) {
		$request = WPNC_AI_Providers::build_request( $slug, 'KEY123', 'a-model', $this->messages(), $options );

		return json_decode( $request['body'], true );
	}

	public function test_every_provider_is_declared_with_what_a_request_needs() {
		foreach ( WPNC_AI_Providers::all() as $slug => $provider ) {
			foreach ( array( 'label', 'endpoint', 'default_model', 'keys_url' ) as $field ) {
				$this->assertArrayHasKey( $field, $provider, $slug . ' is missing ' . $field );
				$this->assertNotSame( '', $provider[ $field ], $slug . ' has an empty ' . $field );
			}
			$this->assertStringStartsWith( 'https://', $provider['endpoint'], $slug . ' must use TLS' );
		}
	}

	public function test_openai_and_groq_share_the_chat_completions_shape() {
		foreach ( array( 'openai', 'groq' ) as $slug ) {
			$body = $this->body( $slug );

			$this->assertSame( 'a-model', $body['model'] );
			$this->assertCount( 2, $body['messages'], $slug . ' should pass both turns through' );
			$this->assertSame( 'system', $body['messages'][0]['role'] );
		}
	}

	public function test_openai_and_groq_send_the_key_as_a_bearer_header() {
		foreach ( array( 'openai', 'groq' ) as $slug ) {
			$request = WPNC_AI_Providers::build_request( $slug, 'KEY123', 'a-model', $this->messages() );

			$this->assertSame( 'Bearer KEY123', $request['headers']['Authorization'] );
			$this->assertStringNotContainsString(
				'KEY123',
				$request['url'],
				'A key in the URL would be logged by proxies.'
			);
		}
	}

	public function test_groq_is_not_pointed_at_openai() {
		$openai = WPNC_AI_Providers::build_request( 'openai', 'K', 'm', $this->messages() );
		$groq   = WPNC_AI_Providers::build_request( 'groq', 'K', 'm', $this->messages() );

		$this->assertNotSame( $openai['url'], $groq['url'] );
		$this->assertStringContainsString( 'groq.com', $groq['url'] );
	}

	public function test_gemini_lifts_the_system_prompt_out_of_the_turns() {
		$body = $this->body( 'gemini' );

		$this->assertSame( 'You are an editor.', $body['systemInstruction']['parts'][0]['text'] );
		$this->assertCount( 1, $body['contents'], 'The system turn must not also appear in contents.' );
		$this->assertSame( 'user', $body['contents'][0]['role'] );
		$this->assertSame( 'Rewrite this.', $body['contents'][0]['parts'][0]['text'] );
	}

	public function test_gemini_carries_the_key_in_the_query_and_the_model_in_the_path() {
		$request = WPNC_AI_Providers::build_request( 'gemini', 'KEY123', 'gemini-2.0-flash', $this->messages() );

		$this->assertStringContainsString( 'key=KEY123', $request['url'] );
		$this->assertStringContainsString( 'gemini-2.0-flash:generateContent', $request['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
	}

	public function test_claude_lifts_the_system_prompt_and_requires_max_tokens() {
		$body = $this->body( 'claude' );

		$this->assertSame( 'You are an editor.', $body['system'] );
		$this->assertCount( 1, $body['messages'] );
		$this->assertSame( 'user', $body['messages'][0]['role'] );
		$this->assertArrayHasKey( 'max_tokens', $body, 'Anthropic rejects a request without it.' );
		$this->assertGreaterThan( 0, $body['max_tokens'] );
	}

	public function test_claude_uses_its_own_auth_headers() {
		$request = WPNC_AI_Providers::build_request( 'claude', 'KEY123', 'a-model', $this->messages() );

		$this->assertSame( 'KEY123', $request['headers']['x-api-key'] );
		$this->assertArrayHasKey( 'anthropic-version', $request['headers'] );
		$this->assertArrayNotHasKey( 'Authorization', $request['headers'] );
	}

	public function test_json_mode_is_expressed_in_each_provider_s_own_terms() {
		$openai = $this->body( 'openai', array( 'json' => true ) );
		$this->assertSame( 'json_object', $openai['response_format']['type'] );

		$gemini = $this->body( 'gemini', array( 'json' => true ) );
		$this->assertSame( 'application/json', $gemini['generationConfig']['responseMimeType'] );
	}

	public function test_content_is_read_from_each_provider_s_response_shape() {
		$this->assertSame(
			'Hello',
			WPNC_AI_Providers::read_content( 'openai', array( 'choices' => array( array( 'message' => array( 'content' => 'Hello' ) ) ) ) )
		);

		$this->assertSame(
			'Hello',
			WPNC_AI_Providers::read_content( 'gemini', array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'Hello' ) ) ) ) ) ) )
		);

		$this->assertSame(
			'Hello',
			WPNC_AI_Providers::read_content( 'claude', array( 'content' => array( array( 'type' => 'text', 'text' => 'Hello' ) ) ) )
		);
	}

	public function test_multi_part_answers_are_joined() {
		$this->assertSame(
			'one two',
			WPNC_AI_Providers::read_content( 'gemini', array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => 'one ' ), array( 'text' => 'two' ) ) ) ) ) ) )
		);

		$this->assertSame(
			'one two',
			WPNC_AI_Providers::read_content( 'claude', array( 'content' => array(
				array( 'type' => 'text', 'text' => 'one ' ),
				array( 'type' => 'text', 'text' => 'two' ),
			) ) )
		);
	}

	public function test_an_unexpected_shape_reads_as_empty_rather_than_erroring() {
		foreach ( WPNC_AI_Providers::slugs() as $slug ) {
			$this->assertSame( '', WPNC_AI_Providers::read_content( $slug, array() ) );
			$this->assertSame( '', WPNC_AI_Providers::read_content( $slug, null ) );
		}
	}

	public function test_the_provider_s_own_error_text_is_surfaced() {
		$this->assertSame(
			'You have no credits remaining.',
			WPNC_AI_Providers::read_error( 'openai', array( 'error' => array( 'message' => 'You have no credits remaining.' ) ) )
		);

		$this->assertSame( '', WPNC_AI_Providers::read_error( 'openai', array() ) );
	}

	/**
	 * @dataProvider rotating_statuses
	 */
	public function test_a_spent_or_rejected_key_steps_aside( $status ) {
		$this->assertTrue( WPNC_AI_Providers::should_rotate( $status ) );
	}

	public function rotating_statuses() {
		return array(
			'no credit'    => array( 402 ),
			'rate limited' => array( 429 ),
			'unauthorised' => array( 401 ),
			'forbidden'    => array( 403 ),
		);
	}

	public function test_a_bad_request_does_not_burn_the_whole_pool() {
		// Every key would be rejected the same way, so rotating just wastes
		// them and hides the real cause.
		$this->assertFalse( WPNC_AI_Providers::should_rotate( 400 ) );
		$this->assertFalse( WPNC_AI_Providers::should_rotate( 404 ) );
	}

	public function test_a_quota_message_behind_a_400_still_rotates() {
		$this->assertTrue(
			WPNC_AI_Providers::should_rotate( 400, 'Your credit balance is too low' ),
			'Some providers answer 400 for an exhausted key.'
		);
		$this->assertTrue( WPNC_AI_Providers::should_rotate( 400, 'API key expired' ) );
		$this->assertFalse( WPNC_AI_Providers::should_rotate( 400, 'model not found' ) );
	}

	public function test_masking_shows_enough_to_recognise_a_key_and_no_more() {
		$masked = WPNC_AI_Providers::mask( 'sk-proj-abcdefghijklmnop1234' );

		$this->assertStringStartsWith( 'sk-p', $masked );
		$this->assertStringEndsWith( '1234', $masked );
		$this->assertStringNotContainsString( 'abcdefghij', $masked );
	}

	public function test_a_short_key_is_masked_entirely() {
		$this->assertSame( '******', WPNC_AI_Providers::mask( 'abcdef' ) );
		$this->assertSame( '', WPNC_AI_Providers::mask( '' ) );
	}

	public function test_an_unknown_provider_falls_back_instead_of_fataling() {
		$this->assertFalse( WPNC_AI_Providers::exists( 'nope' ) );
		$this->assertSame( 'OpenAI', WPNC_AI_Providers::get( 'nope' )['label'] );
	}
}
