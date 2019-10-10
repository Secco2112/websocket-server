<?php

    require 'vendor/autoload.php';
 
    use Ratchet\MessageComponentInterface;
    use Ratchet\ConnectionInterface;
    use Ratchet\Http\HttpServer;
    use Ratchet\Server\IoServer;
    use Ratchet\WebSocket\WsServer;
    
    class UselessChatSocket implements MessageComponentInterface
    {
        protected $clients;
    
        public function __construct() {
            $this->clients = new \SplObjectStorage;
        }

        public function onOpen(ConnectionInterface $conn) {
            $this->clients->attach($conn);
            echo "Cliente conectado ({$conn->resourceId})" . PHP_EOL;
        }

        public function onMessage(ConnectionInterface $from, $data) {
            foreach ($this->clients as $client) {
                $client->send($data);
            }
    
            echo "Cliente {$from->resourceId} enviou uma mensagem: " . $data . PHP_EOL;
        }
    
        public function onClose(ConnectionInterface $conn) {
            $this->clients->detach($conn);
            echo "Cliente {$conn->resourceId} desconectou" . PHP_EOL;
        }

        public function onError(ConnectionInterface $conn, \Exception $e) {
            $conn->close();
    
            echo "Ocorreu um erro: {$e->getMessage()}" . PHP_EOL;
        }
    }


    $server = IoServer::factory(
        new HttpServer(
            new WsServer(
                new UselessChatSocket()
            )
        ),
        8081
    );
     
    $server->run();