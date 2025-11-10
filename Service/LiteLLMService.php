<?php

namespace MauticPlugin\MauticAIconnectionBundle\Service;

use GuzzleHttp\Client;
use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\PluginBundle\Helper\IntegrationHelper;
use Psr\Log\LoggerInterface;

class LiteLLMService
{
    private Client $httpClient;
    private CoreParametersHelper $coreParametersHelper;
    private LoggerInterface $logger;
    private IntegrationHelper $integrationHelper;

    public function __construct(
        Client $httpClient,
        CoreParametersHelper $coreParametersHelper,
        LoggerInterface $logger,
        IntegrationHelper $integrationHelper
    ) {
        $this->httpClient = $httpClient;
        $this->coreParametersHelper = $coreParametersHelper;
        $this->logger = $logger;
        $this->integrationHelper = $integrationHelper;
    }

    public function streamCompletion(string $prompt, callable $onChunk): void
    {
        $config = $this->getConfiguration();

        if (empty($config['endpoint']) || empty($config['secret_key'])) {
            throw new \Exception('LiteLLM endpoint and secret key must be configured');
        }

        $messages = [];

        // Add system message if pre-prompt is configured
        if (!empty($config['pre_prompt'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $config['pre_prompt']
            ];
        }

        // Add user message
        $messages[] = [
            'role' => 'user',
            'content' => $prompt
        ];

        $payload = [
            'model' => $config['model'] ?? 'gpt-3.5-turbo',
            'messages' => $messages,
            'stream' => true,
            'max_tokens' => 4000,
            'temperature' => 0.7,
        ];

        try {
            $response = $this->httpClient->post($config['endpoint'] . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['secret_key'],
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
                'timeout' => 120,
                'stream' => true,
            ]);

            // Handle streaming response with Guzzle
            $body = $response->getBody();
            while (!$body->eof()) {
                $content = $body->read(1024);
                $lines = explode("\n", $content);

                foreach ($lines as $line) {
                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $jsonData = substr($line, 6); // Remove "data: " prefix

                    if ($jsonData === '[DONE]') {
                        break 2;
                    }

                    try {
                        $data = json_decode($jsonData, true);
                        if (isset($data['choices'][0]['delta']['content'])) {
                            $onChunk($data['choices'][0]['delta']['content']);
                        }
                    } catch (\Exception $e) {
                        $this->logger->warning('Failed to parse streaming response: ' . $e->getMessage());
                    }
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('LiteLLM API error: ' . $e->getMessage());
            throw new \Exception('Failed to communicate with AI service: ' . $e->getMessage());
        }
    }

    private function getConfiguration(): array
    {
        try {
            $integration = $this->integrationHelper->getIntegrationObject('AiConnection');
            if ($integration && $integration->isConfigured()) {
                $keys = $integration->getKeys();
                return [
                    'endpoint' => $keys['litellm_endpoint'] ?? '',
                    'secret_key' => $keys['litellm_secret_key'] ?? '',
                    'model' => $keys['ai_model'] ?? 'gpt-3.5-turbo',
                    'pre_prompt' => $keys['pre_prompt'] ?? '',
                ];
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to get AI Connection configuration: ' . $e->getMessage());
        }

        return [];
    }

    public function getAvailableModels(?string $endpoint = null, ?string $secretKey = null): array
    {
        $config = $this->getConfiguration();

        $endpoint = $endpoint ?: $config['endpoint'];
        $secretKey = $secretKey ?: $config['secret_key'];

        if (empty($endpoint) || empty($secretKey)) {
            return $this->getDefaultModels();
        }

        try {
            $response = $this->httpClient->get($endpoint . '/models', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $secretKey,
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 10,
            ]);

            if ($response->getStatusCode() === 200) {
                $data = json_decode($response->getBody()->getContents(), true);
                $models = [];

                if (isset($data['data']) && is_array($data['data'])) {
                    foreach ($data['data'] as $model) {
                        if (isset($model['id'])) {
                            $displayName = $this->formatModelName($model['id']);
                            $models[$displayName] = $model['id'];
                        }
                    }
                }

                return !empty($models) ? $models : $this->getDefaultModels();
            }
        } catch (\Exception $e) {
            $this->logger->warning('Failed to fetch models from LiteLLM API: ' . $e->getMessage());
        }

        return $this->getDefaultModels();
    }

    private function getDefaultModels(): array
    {
        return [
            'GPT-4' => 'gpt-4',
            'GPT-3.5 Turbo' => 'gpt-3.5-turbo',
            'Claude 3 Haiku' => 'claude-3-haiku-20240307',
            'Claude 3 Sonnet' => 'claude-3-sonnet-20240229',
            'Claude 3 Opus' => 'claude-3-opus-20240229',
            'Llama 2 70B' => 'llama-2-70b-chat',
        ];
    }

    private function formatModelName(string $modelId): string
    {
        // Convert model IDs to human-readable names
        $nameMap = [
            'gpt-4' => 'GPT-4',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
            'claude-3-haiku-20240307' => 'Claude 3 Haiku',
            'claude-3-sonnet-20240229' => 'Claude 3 Sonnet',
            'claude-3-opus-20240229' => 'Claude 3 Opus',
            'llama-2-70b-chat' => 'Llama 2 70B',
        ];

        return $nameMap[$modelId] ?? ucfirst(str_replace(['-', '_'], ' ', $modelId));
    }

    public function getCompletion(string $prompt): string
    {
        $config = $this->getConfiguration();

        if (empty($config['endpoint']) || empty($config['secret_key'])) {
            throw new \Exception('LiteLLM endpoint and secret key must be configured');
        }

        $messages = [];

        // Add system message if pre-prompt is configured
        if (!empty($config['pre_prompt'])) {
            $messages[] = [
                'role' => 'system',
                'content' => $config['pre_prompt']
            ];
        }

        // Add user message
        $messages[] = [
            'role' => 'user',
            'content' => $prompt
        ];

        $payload = [
            'model' => $config['model'] ?? 'gpt-3.5-turbo',
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => 4000,
            'temperature' => 0.7,
        ];

        try {
            $response = $this->httpClient->post($config['endpoint'] . '/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $config['secret_key'],
                    'Content-Type' => 'application/json',
                ],
                'body' => json_encode($payload),
                'timeout' => 120,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!isset($responseData['choices'][0]['message']['content'])) {
                throw new \Exception('Invalid response from LiteLLM API');
            }

            return $responseData['choices'][0]['message']['content'];

        } catch (\Exception $e) {
            $this->logger->error('LiteLLM API error: ' . $e->getMessage());
            throw new \Exception('Failed to communicate with AI service: ' . $e->getMessage());
        }
    }

    /**
     * Advanced completion with custom messages array and options
     *
     * @param array $messages Array of message objects with 'role' and 'content'
     * @param array $options Additional options like 'model', 'temperature', 'tools', 'mautic_fingerprint'
     * @return array Full API response data
     * @throws \Exception
     */
    public function getChatCompletion(array $messages, array $options = []): array
    {
        $config = $this->getConfiguration();

        if (empty($config['endpoint']) || empty($config['secret_key'])) {
            throw new \Exception('LiteLLM endpoint and secret key must be configured');
        }

        $payload = [
            'model' => $options['model'] ?? $config['model'] ?? 'gpt-3.5-turbo',
            'messages' => $messages,
            'stream' => false,
            'max_tokens' => $options['max_tokens'] ?? 4000,
            'temperature' => $options['temperature'] ?? 0.7,
        ];

        // Add tools if provided
        if (!empty($options['tools'])) {
            $payload['tools'] = $options['tools'];
            $payload['tool_choice'] = $options['tool_choice'] ?? 'auto';
        }

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $config['secret_key'],
                'Content-Type' => 'application/json',
            ];

            // Add Mautic fingerprint if provided
            if (!empty($options['mautic_fingerprint'])) {
                $headers['Mautic'] = $options['mautic_fingerprint'];
            }

            $response = $this->httpClient->post($config['endpoint'] . '/chat/completions', [
                'headers' => $headers,
                'body' => json_encode($payload),
                'timeout' => 120,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!isset($responseData['choices'][0]['message'])) {
                throw new \Exception('Invalid API response: ' . json_encode($responseData));
            }

            return $responseData;

        } catch (\Exception $e) {
            $this->logger->error('LiteLLM API error: ' . $e->getMessage());
            throw new \Exception('Failed to communicate with AI service: ' . $e->getMessage());
        }
    }

    /**
     * Speech-to-text transcription
     *
     * @param string $audioData Binary audio data
     * @param string $language Language code (e.g., 'en', 'fr', 'auto')
     * @param string $model Model to use (default: 'whisper-1')
     * @param string|null $mauticFingerprint Optional Mautic instance fingerprint
     * @return string Transcribed text
     * @throws \Exception
     */
    public function speechToText(string $audioData, string $language = 'auto', string $model = 'whisper-1', ?string $mauticFingerprint = null): string
    {
        $config = $this->getConfiguration();

        if (empty($config['endpoint']) || empty($config['secret_key'])) {
            throw new \Exception('LiteLLM endpoint and secret key must be configured');
        }

        try {
            $headers = [
                'Authorization' => 'Bearer ' . $config['secret_key'],
            ];

            if ($mauticFingerprint) {
                $headers['Mautic'] = $mauticFingerprint;
            }

            // Build multipart form data for Guzzle
            $multipart = [
                [
                    'name' => 'model',
                    'contents' => $model,
                ],
                [
                    'name' => 'language',
                    'contents' => $language,
                ],
                [
                    'name' => 'file',
                    'contents' => $audioData,
                    'filename' => 'audio.wav',
                    'headers' => [
                        'Content-Type' => 'audio/wav',
                    ],
                ],
            ];

            $transcriptionEndpoint = rtrim($config['endpoint'], '/') . '/audio/transcriptions';

            $response = $this->httpClient->post($transcriptionEndpoint, [
                'headers' => $headers,
                'multipart' => $multipart,
                'timeout' => 60,
            ]);

            $responseData = json_decode($response->getBody()->getContents(), true);

            if (!isset($responseData['text'])) {
                $this->logger->error('Invalid speech-to-text response: ' . json_encode($responseData));
                throw new \Exception('Invalid response from speech-to-text service');
            }

            return $responseData['text'];

        } catch (\Exception $e) {
            $this->logger->error('Speech-to-text error: ' . $e->getMessage());
            throw new \Exception('Failed to communicate with speech-to-text service: ' . $e->getMessage());
        }
    }
}
