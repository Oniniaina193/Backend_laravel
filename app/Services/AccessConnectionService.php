<?php

namespace App\Services;

use PDO;
use Exception;
use Illuminate\Support\Facades\Log;

class AccessConnectionService
{
    private static $connections = [];
    private static $lastUsed = [];
    private const MAX_CONNECTIONS = 10;
    private const CONNECTION_TIMEOUT = 300; // 5 minutes
    private const RETRY_ATTEMPTS = 3;
    private const RETRY_DELAY = 1000000; // 1 seconde en microsecondes

    /**
     * Obtenir une connexion avec pool et retry
     */
    public static function getConnection(string $dbPath): PDO
    {
        $connectionKey = md5($dbPath);
        
        // Nettoyer les connexions expirées
        self::cleanupExpiredConnections();
        
        // Vérifier si une connexion existe
        if (isset(self::$connections[$connectionKey])) {
            try {
                // Tester la connexion
                self::$connections[$connectionKey]->query("SELECT 1");
                self::$lastUsed[$connectionKey] = time();
                return self::$connections[$connectionKey];
            } catch (Exception $e) {
                self::closeConnection($connectionKey);
            }
        }

        // Créer nouvelle connexion avec retry
        return self::createConnectionWithRetry($dbPath, $connectionKey);
    }

    /**
     * Créer une connexion avec système de retry
     */
    private static function createConnectionWithRetry(string $dbPath, string $connectionKey): PDO
    {
        $lastException = null;
        
        for ($attempt = 1; $attempt <= self::RETRY_ATTEMPTS; $attempt++) {
            try {
                // Vérifier la limite de connexions
                if (count(self::$connections) >= self::MAX_CONNECTIONS) {
                    self::closeOldestConnection();
                }

                $dsn = "odbc:DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=" . $dbPath . ";";
                
                $pdo = new PDO($dsn, '', '', [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_TIMEOUT => 30,
                    PDO::ATTR_PERSISTENT => false // Éviter les connexions persistantes
                ]);

                self::$connections[$connectionKey] = $pdo;
                self::$lastUsed[$connectionKey] = time();
                
                Log::info('Connexion ODBC créée', [
                    'path' => $dbPath,
                    'attempt' => $attempt,
                    'total_connections' => count(self::$connections)
                ]);
                
                return $pdo;
                
            } catch (Exception $e) {
                $lastException = $e;
                
                Log::warning('Tentative de connexion échouée', [
                    'path' => $dbPath,
                    'attempt' => $attempt,
                    'error' => $e->getMessage()
                ]);
                
                if ($attempt < self::RETRY_ATTEMPTS) {
                    usleep(self::RETRY_DELAY * $attempt); // Délai progressif
                }
            }
        }

        throw new Exception("Impossible de créer la connexion après " . self::RETRY_ATTEMPTS . " tentatives. Dernière erreur: " . $lastException->getMessage());
    }

    /**
     * Fermer la connexion la plus ancienne
     */
    private static function closeOldestConnection(): void
    {
        if (empty(self::$lastUsed)) {
            return;
        }
        
        $oldestKey = array_keys(self::$lastUsed, min(self::$lastUsed))[0];
        self::closeConnection($oldestKey);
    }

    /**
     * Fermer une connexion spécifique
     */
    private static function closeConnection(string $connectionKey): void
    {
        if (isset(self::$connections[$connectionKey])) {
            try {
                self::$connections[$connectionKey] = null;
            } catch (Exception $e) {
                Log::warning('Erreur fermeture connexion', ['error' => $e->getMessage()]);
            }
            unset(self::$connections[$connectionKey]);
            unset(self::$lastUsed[$connectionKey]);
        }
    }

    /**
     * Nettoyer les connexions expirées
     */
    private static function cleanupExpiredConnections(): void
    {
        $currentTime = time();
        $expiredKeys = [];
        
        foreach (self::$lastUsed as $key => $lastUsedTime) {
            if (($currentTime - $lastUsedTime) > self::CONNECTION_TIMEOUT) {
                $expiredKeys[] = $key;
            }
        }
        
        foreach ($expiredKeys as $key) {
            self::closeConnection($key);
        }
        
        if (!empty($expiredKeys)) {
            Log::info('Connexions expirées nettoyées', ['count' => count($expiredKeys)]);
        }
    }

    /**
     * Fermer toutes les connexions
     */
    public static function closeAllConnections(): void
    {
        foreach (array_keys(self::$connections) as $key) {
            self::closeConnection($key);
        }
        
        Log::info('Toutes les connexions ODBC fermées', [
            'total_closed' => count(self::$connections)
        ]);
        
        self::$connections = [];
        self::$lastUsed = [];
    }

    /**
     * Obtenir les statistiques des connexions
     */
    public static function getConnectionStats(): array
    {
        return [
            'active_connections' => count(self::$connections),
            'max_connections' => self::MAX_CONNECTIONS,
            'connection_timeout' => self::CONNECTION_TIMEOUT,
            'oldest_connection_age' => !empty(self::$lastUsed) ? time() - min(self::$lastUsed) : 0,
            'newest_connection_age' => !empty(self::$lastUsed) ? time() - max(self::$lastUsed) : 0
        ];
    }

    /**
     * Tester une connexion sans la stocker dans le pool
     */
    public static function testConnection(string $dbPath): array
    {
        try {
            $startTime = microtime(true);
            
            $dsn = "odbc:DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ=" . $dbPath . ";";
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // Test simple
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM Article");
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            
            // Fermer immédiatement la connexion de test
            $pdo = null;
            
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            
            return [
                'success' => true,
                'article_count' => $result['total'] ?? 0,
                'connection_time_ms' => $duration,
                'file_path' => $dbPath
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'file_path' => $dbPath
            ];
        }
    }
}