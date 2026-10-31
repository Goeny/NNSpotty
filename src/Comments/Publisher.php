<?php

declare(strict_types=1);

namespace NnSpotty\Comments;

use NnSpotty\State\AppStateRepository;
use NnSpotty\Usenet\NntpClientFactory;
use NnSpotty\Usenet\PipelineConnection;
use NnSpotty\Usenet\SpotnetParser;
use NnSpotty\Usenet\SpotRepository;
use PDO;
use Rvdv\Nntp\Command\PostArticleCommand;
use Rvdv\Nntp\Command\PostCommand;
use Rvdv\Nntp\Exception\RuntimeException as NntpException;

final class Publisher
{
    public function __construct(private readonly PDO $pdo, private readonly AppStateRepository $state)
    {
    }

    public function publish(string $username, array $spot, string $body, string $requestId): void
    {
        $sourceBody = Article::body($body);
        $account = (new \NnSpotty\Users\UserRepository($this->pdo))->findByUsername($username);
        if (!\NnSpotty\Users\Permissions::allows($account, 'comments.publish')) {
            throw new PublicationException('Je account mag geen reacties publiceren.');
        }
        $poster = $account['display_name'];
        if (!preg_match('/^[a-f0-9]{64}$/D', $requestId)) throw new \InvalidArgumentException('Ongeldig publicatieformulier. Herlaad de spot.');
        if (!$this->state->spotContentSettings()['show_comments']) throw new PublicationException('Reacties zijn door de beheerder uitgeschakeld.');
        $settings = $this->state->getUsenetSettings();
        $groups = array_keys(SpotnetParser::groups($settings['explicit_groups']), 'comments', true);
        if (count($groups) !== 1) throw new PublicationException('Publiceren is niet mogelijk door de serverconfiguratie: stel precies een Usenet-reactiegroep in (comments:free.usenet).');
        $lockName = 'nnsc:' . substr(hash('sha256', $username), 0, 59);
        $lock = $this->pdo->prepare('SELECT GET_LOCK(:name, 0)');
        $lock->execute(['name' => $lockName]);
        if ((int) $lock->fetchColumn() !== 1) throw new PublicationException('Er wordt al een reactie voor jouw account verwerkt. Wacht op de uitkomst.');
        $client = null;
        $claimed = false;
        $sending = false;
        $accepted = false;
        try {
            $existing = $this->pdo->prepare('SELECT * FROM comment_publications WHERE username = :username AND request_id = :request');
            $existing->execute(['username' => $username, 'request' => $requestId]);
            $previous = $existing->fetch();
            if (is_array($previous)) {
                if ($previous['spot_id'] !== $spot['id'] || ($previous['source_body'] ?? $previous['body']) !== $sourceBody) {
                    throw new PublicationException('Dit publicatieformulier is al gebruikt voor een andere reactie. Herlaad de spot.');
                }
                if ($previous['status'] === 'posted') return;
                $retrieved = $this->pdo->prepare('SELECT spot_id, poster, body FROM spotnet_comments WHERE message_id = :message AND body_status = \'loaded\'');
                $retrieved->execute(['message' => $previous['message_id']]);
                $retrievedComment = $retrieved->fetch();
                if (is_array($retrievedComment) && $retrievedComment['spot_id'] === $spot['id']
                    && $retrievedComment['poster'] === ($previous['poster'] ?? $username)
                    && trim(str_replace(["\r\n", "\r"], "\n", $retrievedComment['body'])) === $previous['body']) {
                    $this->status($username, $requestId, 'posted');
                    return;
                }
                throw new PublicationException($previous['error_message']
                    ?? 'De eerdere verzendpoging heeft geen bevestigde uitkomst. Niet opnieuw verzonden; controleer eerst of de reactie op Usenet staat.');
            }
            $body = Article::body($sourceBody . ($account['signature'] !== '' ? "\n\n-- \n" . $account['signature'] : ''));
            $rate = $this->pdo->prepare('SELECT COUNT(*) FROM comment_publications WHERE username = :username AND created_at > UTC_TIMESTAMP() - INTERVAL 30 SECOND');
            $rate->execute(['username' => $username]);
            if ((int) $rate->fetchColumn() > 0) throw new PublicationException('Wacht minimaal 30 seconden tussen publicatiepogingen.');
            $userKey = $this->key('user:' . hash('sha256', $username), $username);
            $serverKey = $this->key('server');
            // Validate server signing support before proof-of-work or opening a provider connection.
            Article::sign('NNSpotty signing capability check', Article::privateKey($userKey));
            $id = Article::messageId($spot['message_id']);
            $article = Article::build($spot, $poster, $body, $id, $userKey, $serverKey);
            $insert = $this->pdo->prepare('INSERT INTO comment_publications
                (username, request_id, spot_id, message_id, body, source_body, poster, status, created_at)
                VALUES (:username, :request, :spot, :message, :body, :source, :poster, \'prepared\', UTC_TIMESTAMP())');
            $insert->execute(['username' => $username, 'request' => $requestId, 'spot' => $spot['id'], 'message' => $id,
                'body' => $body, 'source' => $sourceBody, 'poster' => $poster]);
            $claimed = true;
            try {
                $client = NntpClientFactory::connectFromSettings(NntpClientFactory::postingSettings($settings));
                $ready = $client->sendCommand(new PostCommand());
                if ($ready->getStatusCode() !== 340) throw new NntpException('Provider accepteert geen POST.');
            } catch (\RuntimeException $exception) {
                if ($exception instanceof \PDOException) throw $exception;
                error_log('Usenet-reactie voorbereiden mislukt (' . $requestId . '): ' . $exception->getMessage());
                throw new PublicationException('De Usenet-verbinding kon niet voor publiceren worden geopend of de provider staat posten niet toe. Er is geen reactie verstuurd. Controleer de Usenet-instellingen en serverlog.', 0, $exception);
            }
            $connection = $client->getConnection();
            if (!$connection instanceof PipelineConnection) throw new \LogicException('Reactiepublicatie vereist een NNSpotty-verbinding.');
            $this->status($username, $requestId, 'sending');
            $sending = true;
            $connection->publishComment(new PostArticleCommand($groups[0], $article['subject'], $article['body'], $article['from'], $article['headers']));
            $accepted = true;
            $this->pdo->beginTransaction();
            try {
                (new SpotRepository($this->pdo))->saveComment([
                    'id' => hash('sha256', $id), 'message_id' => $id, 'spot_id' => $spot['id'],
                    'poster' => $poster, 'spotter_id' => $article['spotter_id'],
                    'signature_state' => 'verified', 'body' => $body, 'posted_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->status($username, $requestId, 'posted');
                $this->pdo->commit();
            } catch (\Throwable $exception) {
                $this->pdo->rollBack();
                throw $exception;
            }
        } catch (\Throwable $exception) {
            $definiteRejection = $exception instanceof NntpException && $exception->getCode() === 441;
            $unknown = $sending && !$definiteRejection;
            $message = $accepted
                ? 'De Usenet-provider heeft de reactie geaccepteerd, maar de lokale bevestiging kon niet worden opgeslagen. Niet opnieuw publiceren; de retrieve kan de reactie later ophalen.'
                : ($unknown
                    ? 'De verbinding is verbroken of de provider gaf geen geldige bevestiging. De reactie kan al op Usenet staan. Deze poging wordt niet opnieuw verstuurd; controleer eerst via retrieve of een andere reader.'
                    : ($definiteRejection ? 'De Usenet-provider heeft de reactie geweigerd. Er is geen publicatie bevestigd.'
                        : ($exception instanceof PublicationException || $exception instanceof \InvalidArgumentException
                            ? $exception->getMessage() : 'Publiceren kon niet worden voorbereid door een serverfout. Controleer de serverlog.')));
            if ($claimed) {
                try {
                    $this->status($username, $requestId, $unknown ? 'unknown' : 'failed', $message);
                } catch (\PDOException $databaseError) {
                    error_log('Publicatiestatus opslaan mislukt (' . $requestId . '): ' . $databaseError->getMessage());
                }
            }
            if ($sending) {
                error_log('Usenet-reactie verzenden mislukt (' . $requestId . '): ' . $exception->getMessage());
                throw new PublicationException($message, 0, $exception);
            }
            throw $exception;
        } finally {
            if ($client !== null) {
                try {
                    $client->getConnection()->disconnect();
                } catch (\RuntimeException $exception) {
                    error_log('Usenet-reactieverbinding sluiten mislukt: ' . $exception->getMessage());
                }
            }
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
            $release->execute(['name' => $lockName]);
            if ((int) $release->fetchColumn() !== 1) throw new \RuntimeException('Publicatielock kon niet worden vrijgegeven.');
        }
    }

    private function key(string $scope, ?string $username = null): string
    {
        $statement = $this->pdo->prepare('SELECT private_key FROM comment_signing_keys WHERE scope = :scope');
        $statement->execute(['scope' => $scope]);
        $key = $statement->fetchColumn();
        if (is_string($key) && Article::compatibleKey($key)) return $key;
        $pem = Article::key();
        if (is_string($key)) {
            // Replace older 2048-bit keys once: their signatures do not fit in Spotweb's database. This changes the Spotnet-ID.
            $replace = $this->pdo->prepare('UPDATE comment_signing_keys SET private_key = :new WHERE scope = :scope AND private_key = :old');
            $replace->execute(['new' => $pem, 'scope' => $scope, 'old' => $key]);
        } else {
            $insert = $this->pdo->prepare('INSERT INTO comment_signing_keys (scope, username, private_key) VALUES (:scope, :username, :key)
                ON DUPLICATE KEY UPDATE scope = VALUES(scope)');
            $insert->execute(['scope' => $scope, 'username' => $username, 'key' => $pem]);
        }
        $statement->execute(['scope' => $scope]);
        $stored = $statement->fetchColumn();
        if (!is_string($stored) || !Article::compatibleKey($stored)) throw new \RuntimeException('Reactiesleutel kon niet worden opgeslagen.');
        return $stored;
    }

    private function status(string $username, string $requestId, string $status, ?string $message = null): void
    {
        $statement = $this->pdo->prepare('UPDATE comment_publications SET status = :status, error_message = :message
            WHERE username = :username AND request_id = :request');
        $statement->execute(['status' => $status, 'message' => $message, 'username' => $username, 'request' => $requestId]);
    }
}
