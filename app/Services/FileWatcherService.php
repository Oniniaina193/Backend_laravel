<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Carbon\Carbon;

class FileWatcherService
{
    private const CACHE_PREFIX = 'file_watcher_';
    private const CHECK_INTERVAL = 2; // Secondes entre les vérifications

    /**
     * Surveiller les changements dans les fichiers Access
     */
    public function watchFiles($selectedFolder): array
    {
        $changes = [];
        
        try {
            // Obtenir les chemins des fichiers
            $filePaths = $this->getAccessFilePaths($selectedFolder);
            
            foreach ($filePaths as $fileType => $filePath) {
                if (file_exists($filePath)) {
                    $change = $this->checkFileChange($fileType, $filePath);
                    if ($change) {
                        $changes[] = $change;
                    }
                }
            }
            
        } catch (\Exception $e) {
            Log::error('Erreur surveillance fichiers', [
                'error' => $e->getMessage()
            ]);
        }
        
        return $changes;
    }

    /**
     * Vérifier si un fichier a changé
     */
    private function checkFileChange(string $fileType, string $filePath): ?array
    {
        $cacheKey = self::CACHE_PREFIX . md5($filePath);
        
        // Obtenir les informations actuelles du fichier
        $currentStats = [
            'modified_time' => filemtime($filePath),
            'size' => filesize($filePath),
            'checksum' => md5_file($filePath)
        ];
        
        // Récupérer les stats précédentes du cache
        $previousStats = Cache::get($cacheKey);
        
        // Première vérification - sauvegarder et continuer
        if (!$previousStats) {
            Cache::put($cacheKey, $currentStats, now()->addHours(24));
            return null;
        }
        
        // Vérifier s'il y a eu des changements
        $hasChanged = (
            $currentStats['modified_time'] !== $previousStats['modified_time'] ||
            $currentStats['size'] !== $previousStats['size'] ||
            $currentStats['checksum'] !== $previousStats['checksum']
        );
        
        if ($hasChanged) {
            // Mettre à jour le cache
            Cache::put($cacheKey, $currentStats, now()->addHours(24));
            
            return [
                'file_type' => $fileType,
                'file_path' => $filePath,
                'change_time' => Carbon::now(),
                'change_details' => [
                    'size_changed' => $currentStats['size'] !== $previousStats['size'],
                    'modified_time_changed' => $currentStats['modified_time'] !== $previousStats['modified_time'],
                    'content_changed' => $currentStats['checksum'] !== $previousStats['checksum']
                ]
            ];
        }
        
        return null;
    }

    /**
     * Obtenir tous les chemins des fichiers Access
     */
    private function getAccessFilePaths($selectedFolder): array
    {
        $paths = [];
        
        if (isset($selectedFolder['stored_file_path'])) {
            // Fichiers uploadés
            $caissPath = storage_path('app/' . $selectedFolder['stored_file_path']);
            $folderPath = dirname($caissPath);
            
            $paths = [
                'caiss' => $caissPath,
                'facturation' => $folderPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb',
                'frontoffice' => $folderPath . DIRECTORY_SEPARATOR . 'Caiss_frontoffice.mdb'
            ];
        } else if (isset($selectedFolder['folder_path'])) {
            // Chemins traditionnels
            $folderPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $selectedFolder['folder_path']);
            
            $paths = [
                'caiss' => $folderPath . DIRECTORY_SEPARATOR . 'Caiss.mdb',
                'facturation' => $folderPath . DIRECTORY_SEPARATOR . 'caiss_facturation.mdb',
                'frontoffice' => $folderPath . DIRECTORY_SEPARATOR . 'Caiss_frontoffice.mdb'
            ];
        }
        
        return $paths;
    }

    /**
     * Réinitialiser la surveillance d'un dossier
     */
    public function resetWatcher($selectedFolder): void
    {
        try {
            $filePaths = $this->getAccessFilePaths($selectedFolder);
            
            foreach ($filePaths as $filePath) {
                $cacheKey = self::CACHE_PREFIX . md5($filePath);
                Cache::forget($cacheKey);
            }
            
            Log::info('Surveillance réinitialisée', [
                'folder' => $selectedFolder['name'] ?? 'Unknown'
            ]);
            
        } catch (\Exception $e) {
            Log::error('Erreur réinitialisation surveillance', [
                'error' => $e->getMessage()
            ]);
        }
    }
}