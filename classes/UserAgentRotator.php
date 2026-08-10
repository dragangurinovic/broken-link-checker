<?php

class UserAgentRotator {
    private array $browserAgents = [];
    private string $botAgent = '';
    private int $index = 0;

    public function __construct(PDO $db) {
        $stmt = $db->query("SELECT agent_string, agent_type FROM user_agents WHERE enabled = 1");
        $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($agents as $a) {
            if ($a['agent_type'] === 'bot') {
                $this->botAgent = $a['agent_string'];
            } else {
                $this->browserAgents[] = $a['agent_string'];
            }
        }

        if (empty($this->botAgent) && !empty($this->browserAgents)) {
            $this->botAgent = $this->browserAgents[0];
        }
    }

    public function getBotAgent(): string {
        return $this->botAgent;
    }

    public function getBrowserAgent(): string {
        if (empty($this->browserAgents)) {
            return $this->botAgent;
        }
        $agent = $this->browserAgents[$this->index % count($this->browserAgents)];
        $this->index++;
        return $agent;
    }

    public function getHeaders(bool $asBrowser = false): array {
        $ua = $asBrowser ? $this->getBrowserAgent() : $this->getBotAgent();
        $headers = [
            'User-Agent: ' . $ua,
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.5',
            'Accept-Encoding: gzip, deflate',
            'Connection: keep-alive',
        ];
        if ($asBrowser) {
            $headers[] = 'Sec-Fetch-Dest: document';
            $headers[] = 'Sec-Fetch-Mode: navigate';
            $headers[] = 'Sec-Fetch-Site: none';
            $headers[] = 'Sec-Fetch-User: ?1';
            $headers[] = 'Upgrade-Insecure-Requests: 1';
        }
        return $headers;
    }
}
