<?php

declare(strict_types=1);

namespace Drupal\Tests\voting_api\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\User;
use Drupal\voting_core\Entity\Option;
use Drupal\voting_core\Entity\Question;

/**
 * Functional tests for the voting REST API.
 *
 * @group voting_api
 */
final class VotingApiTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'voting_api',
    'voting_core',
    'user',
    'system',
    'field',
    'options',
    'datetime',
    'file',
    'image',
  ];

  /**
   * A question used by the tests.
   */
  private Question $question;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // The API routes require the "access content" permission.
    $this->grantPermissions(
      \Drupal::entityTypeManager()->getStorage('user_role')->load('anonymous'),
      ['access content']
    );

    $this->question = Question::create([
      'identifier' => 'favorite-color',
      'title' => 'What is your favorite color?',
      'status' => 1,
      'show_results' => 1,
    ]);
    $this->question->save();

    Option::create([
      'question' => $this->question->id(),
      'identifier' => 'red',
      'title' => 'Red',
      'weight' => 0,
    ])->save();

    Option::create([
      'question' => $this->question->id(),
      'identifier' => 'blue',
      'title' => 'Blue',
      'weight' => 1,
    ])->save();
  }

  /**
   * Performs a JSON API request and returns the decoded body and status.
   *
   * @return array{0: mixed, 1: int}
   *   The decoded JSON body and the HTTP status code.
   */
  private function apiRequest(string $method, string $path, ?array $json = NULL): array {
    $client = $this->getSession()->getDriver()->getClient();
    $content = $json === NULL ? NULL : json_encode($json);
    $server = ['CONTENT_TYPE' => 'application/json'];

    $client->request($method, $this->baseUrl . $path, [], [], $server, $content);

    $response = $client->getInternalResponse();
    $decoded = json_decode($response->getContent(), TRUE);

    return [$decoded, $response->getStatusCode()];
  }

  /**
   * Creates and returns an authenticated user.
   */
  private function createAuthenticatedUser(): User {
    $user = $this->drupalCreateUser(['access content', 'cast_vote']);
    $this->drupalLogin($user);
    return $user;
  }

  /**
   * Tests listing active questions.
   */
  public function testGetActiveQuestions(): void {
    [$body, $status] = $this->apiRequest('GET', '/api/voting/questions');

    $this->assertEquals(200, $status);
    $this->assertArrayHasKey('questions', $body);
    $this->assertNotEmpty($body['questions']);
    $this->assertEquals('favorite-color', $body['questions'][0]['identifier']);
  }

  /**
   * Tests retrieving a single question.
   */
  public function testGetSingleQuestion(): void {
    [$body, $status] = $this->apiRequest('GET', '/api/voting/questions/favorite-color');

    $this->assertEquals(200, $status);
    $this->assertArrayHasKey('question', $body);
    $this->assertEquals('favorite-color', $body['question']['identifier']);
    $this->assertCount(2, $body['question']['options']);
  }

  /**
   * Tests that a non-existent question returns 404.
   */
  public function testGetNonExistentQuestionReturns404(): void {
    [, $status] = $this->apiRequest('GET', '/api/voting/questions/does-not-exist');

    $this->assertEquals(404, $status);
  }

  /**
   * Tests casting a vote successfully.
   */
  public function testCastVoteSuccessfully(): void {
    $this->createAuthenticatedUser();

    [$body, $status] = $this->apiRequest('POST', '/api/voting/vote', [
      'question_identifier' => 'favorite-color',
      'option_identifier' => 'red',
    ]);

    $this->assertEquals(200, $status);
    $this->assertEquals('Vote registered successfully.', $body['message']);
  }

  /**
   * Tests that anonymous users cannot vote.
   */
  public function testAnonymousUserCannotVote(): void {
    [$body, $status] = $this->apiRequest('POST', '/api/voting/vote', [
      'question_identifier' => 'favorite-color',
      'option_identifier' => 'red',
    ]);

    $this->assertEquals(403, $status);
    $this->assertEquals('Anonymous users are not allowed to vote.', $body['error']);
  }

  /**
   * Tests that a duplicate vote is blocked.
   */
  public function testDuplicateVoteIsBlocked(): void {
    $this->createAuthenticatedUser();

    $this->apiRequest('POST', '/api/voting/vote', [
      'question_identifier' => 'favorite-color',
      'option_identifier' => 'red',
    ]);

    [$body, $status] = $this->apiRequest('POST', '/api/voting/vote', [
      'question_identifier' => 'favorite-color',
      'option_identifier' => 'red',
    ]);

    $this->assertEquals(409, $status);
    $this->assertEquals('You have already voted for this question.', $body['error']);
  }

  /**
   * Tests retrieving results when allowed.
   */
  public function testGetResultsWhenAllowed(): void {
    $this->createAuthenticatedUser();
    $this->apiRequest('POST', '/api/voting/vote', [
      'question_identifier' => 'favorite-color',
      'option_identifier' => 'red',
    ]);

    [$body, $status] = $this->apiRequest('GET', '/api/voting/questions/favorite-color/results');

    $this->assertEquals(200, $status);
    $this->assertEquals(1, $body['total_votes']);
    $this->assertCount(2, $body['results']);
  }

  /**
   * Tests that results are hidden when disabled.
   */
  public function testResultsHiddenWhenDisabled(): void {
    $question = Question::create([
      'identifier' => 'hidden-results',
      'title' => 'Hidden results?',
      'status' => 1,
      'show_results' => 0,
    ]);
    $question->save();

    Option::create([
      'question' => $question->id(),
      'identifier' => 'yes',
      'title' => 'Yes',
    ])->save();

    [, $status] = $this->apiRequest('GET', '/api/voting/questions/hidden-results/results');

    $this->assertEquals(403, $status);
  }

  /**
   * Tests that voting is blocked when globally disabled.
   */
  public function testVotingBlockedWhenGloballyDisabled(): void {
    $this->createAuthenticatedUser();

    \Drupal::configFactory()->getEditable('voting_core.settings')
      ->set('voting_enabled', FALSE)
      ->save();

    [$body, $status] = $this->apiRequest('POST', '/api/voting/vote', [
      'question_identifier' => 'favorite-color',
      'option_identifier' => 'red',
    ]);

    $this->assertEquals(403, $status);
    $this->assertEquals('Voting is currently disabled.', $body['error']);
  }

  /**
   * Tests that invalid JSON returns an error.
   */
  public function testInvalidJsonReturnsError(): void {
    $client = $this->getSession()->getDriver()->getClient();
    $client->request('POST', $this->baseUrl . '/api/voting/vote', [], [], [
      'CONTENT_TYPE' => 'application/json',
    ], '{invalid json');

    $response = $client->getInternalResponse();
    $body = json_decode($response->getContent(), TRUE);

    $this->assertEquals(400, $response->getStatusCode());
    $this->assertEquals('Invalid JSON payload.', $body['error']);
  }

  /**
   * Tests that missing fields return an error.
   */
  public function testMissingFieldsInVoteRequest(): void {
    [$body, $status] = $this->apiRequest('POST', '/api/voting/vote', [
      'question_identifier' => 'favorite-color',
    ]);

    $this->assertEquals(400, $status);
    $this->assertArrayHasKey('error', $body);
  }
}
