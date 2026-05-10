<?php

namespace App\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

class BggApiService
{
    private Client $client;
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->client = new Client([
            'base_uri' => 'https://www.boardgamegeek.com/xmlapi2/',
            'timeout' => 10, // Augmenté à 10s pour éviter les timeouts
        ]);
        $this->logger = $logger;
    }

    /**
     * Recherche des jeux sur BGG par nom.
     *
     * @param string $query Le terme de recherche
     * @return array Liste des jeux trouvés
     */
    public function searchGames(string $query): array
    {
        if (empty($query)) {
            $this->logger->info('Recherche BGG : query vide, retour tableau vide');
            return [];
        }

        try {
            $this->logger->info('Recherche BGG pour le terme', ['query' => $query]);
            $response = $this->client->get('search', [
                'query' => ['query' => $query, 'type' => 'boardgame'],
            ]);

            $body = (string)$response->getBody();
            $this->logger->debug('Réponse brute BGG pour recherche', ['body' => $body]);

            $xml = simplexml_load_string($body);
            if ($xml === false) {
                $this->logger->error('Erreur lors du parsing XML de la recherche BGG', ['query' => $query]);
                return [];
            }

            $games = [];
            if (!isset($xml->item)) {
                $this->logger->warning('Aucun item trouvé dans la réponse XML', ['query' => $query]);
                return [];
            }

            foreach ($xml->item as $item) {
                $name = isset($item->name['value']) ? (string)$item->name['value'] : '';
                if ($name) {
                    $games[] = [
                        'id' => (string)$item['id'],
                        'name' => $name,
                        'year' => isset($item->yearpublished['value']) ? (string)$item->yearpublished['value'] : null,
                    ];
                }
            }

            // Trier par ordre alphabétique sur le nom
            usort($games, fn($a, $b) => strcmp($a['name'], $b['name']));
            $this->logger->info('Recherche BGG réussie', ['query' => $query, 'count' => count($games)]);

            return $games;
        } catch (GuzzleException $e) {
            $this->logger->error('Erreur de Guzzle lors de la recherche BGG: ' . $e->getMessage(), [
                'query' => $query,
                'exception' => $e,
            ]);
            return [];
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la recherche BGG: ' . $e->getMessage(), [
                'query' => $query,
                'exception' => $e,
            ]);
            return [];
        }
    }

    /**
     * Récupère les détails d'un jeu par son ID BGG.
     *
     * @param string $id L'ID BGG du jeu
     * @return array|null Les détails du jeu ou null si non trouvé
     */
    public function getGameDetails(string $id): ?array
    {
        try {
            $this->logger->info('Récupération des détails du jeu BGG', ['gameId' => $id]);
            $response = $this->client->get("thing?id={$id}&type=boardgame");

            $body = (string)$response->getBody();
            $this->logger->debug('Réponse brute BGG pour détails', ['body' => $body]);

            $xml = simplexml_load_string($body);
            if ($xml === false) {
                $this->logger->error('Erreur lors du parsing XML pour le jeu', ['gameId' => $id]);
                return null;
            }

            $item = $xml->item[0] ?? null;
            if (!$item) {
                $this->logger->warning('Aucun item trouvé dans la réponse XML', ['gameId' => $id]);
                return null;
            }

            $name = isset($item->name[0]['value']) ? (string)$item->name[0]['value'] : '';
            $image = isset($item->image) ? (string)$item->image : '';

            if (!$name) {
                $this->logger->warning('Aucun nom trouvé pour le jeu', ['gameId' => $id]);
                return null;
            }

            $details = [
                'id' => $id,
                'name' => $name,
                'image' => $image,
            ];

            $this->logger->info('Détails du jeu BGG récupérés avec succès', ['gameId' => $id, 'details' => $details]);
            return $details;
        } catch (GuzzleException $e) {
            $this->logger->error('Erreur de Guzzle lors de la récupération des détails du jeu: ' . $e->getMessage(), [
                'gameId' => $id,
                'exception' => $e,
            ]);
            return null;
        } catch (\Exception $e) {
            $this->logger->error('Erreur lors de la récupération des détails du jeu: ' . $e->getMessage(), [
                'gameId' => $id,
                'exception' => $e,
            ]);
            return null;
        }
    }
}
