<?php

declare(strict_types=1);

namespace NnSpotty\Comments;

use NnSpotty\State\AppStateRepository;
use NnSpotty\Usenet\NntpClientFactory;
use NnSpotty\Usenet\PipelineConnection;
use NnSpotty\Usenet\SpotnetParser;
use NnSpotty\Usenet\SpotRepository;
use PDO;
use Rvdv\Nntp\Exception\RuntimeException as NntpException;
use UnexpectedValueException;

/** Fetches the bodies of header-only Usenet comments for one spot and caches them in the database. */
final class BodyLoader
{
    public const BATCH_LIMIT = 100;

    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state)
    {
    }

    /** @return array{loaded: int, unavailable: int, invalid: int, remaining: int} */
    public function load(string $spotId, int $limit = self::BATCH_LIMIT): array
    {
        $repository = new SpotRepository($this->pdo);
        $messageIds = $repository->pendingCommentMessageIds($spotId, $limit);
        $result = ['loaded' => 0, 'unavailable' => 0, 'invalid' => 0, 'remaining' => 0];
        if ($messageIds !== []) {
            $settings = $this->state->getUsenetSettings();
            $client = NntpClientFactory::connectFromSettings($settings);
            $loaded = [];
            $unavailable = [];
            $invalid = [];
            try {
                $connection = $client->getConnection();
                if (!$connection instanceof PipelineConnection) {
                    throw new \LogicException('Reacties laden vereist een pipelineverbinding.');
                }
                foreach ($connection->articles($messageIds, false, $settings['pipeline_depth']) as $index => $article) {
                    $messageId = $messageIds[$index];
                    if ($article instanceof NntpException) {
                        $unavailable[] = $messageId;
                        continue;
                    }
                    try {
                        $loaded[] = SpotnetParser::comment($messageId, $article);
                    } catch (UnexpectedValueException) {
                        $invalid[] = $messageId;
                    }
                }
            } finally {
                $client->disconnect();
            }
            $this->pdo->beginTransaction();
            try {
                $repository->saveComments($loaded);
                $repository->markComments($unavailable, 'unavailable');
                $repository->markComments($invalid, 'invalid');
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
            $result = ['loaded' => count($loaded), 'unavailable' => count($unavailable), 'invalid' => count($invalid), 'remaining' => 0];
        }
        $result['remaining'] = $repository->pendingCommentCount($spotId);
        return $result;
    }
}
