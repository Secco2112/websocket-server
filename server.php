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
            $this->connection_clients = new \SplObjectStorage;
            $this->clients_sets = [];
        }

        public function onOpen(ConnectionInterface $conn) {
            $this->connection_clients->attach($conn);
            $conn->send(json_encode(["id" => $conn->resourceId, "type" => "first_connection"]));
        }

        public function onMessage(ConnectionInterface $from, $data) {
            $data = json_decode($data);

            $type = $data->type;

            if($type == "first_connection_client_info") {
                $client = $this->findClientBy("socketId", $data->socketId);
                if($client == false) {
                    $client = $data;
                    unset($client->type);
                    $this->clients->attach($client);
                }
            }

            else if($type == "clients_sets") {
                if($data->scope == "private") {
                    $sender = $this->findClientBy("chatId", $data->sender_chat_id);
                    $receiver = $this->findClientBy("chatId", $data->receiver_chat_id);

                    // Verifica se o cliente $receiver está conectado ao servidor
                    if($receiver) {
                        $this->clients_sets["private"][] = [
                            "sender" => $sender,
                            "receiver" => $receiver
                        ];
                    }
                }
            }

            else if($type == "message") {
                if($data->scope == "private") {
                    $sender_chat_id = $data->sender_chat_id;
                    $sender_socket_id = $data->sender_socket_id;

                    $receiver_client = null;
                    $sender_client = null;
                    foreach ($this->clients_sets as $type => $group) {
                        if($type == "private") {
                            foreach ($group as $key => $cs) {
                                if($cs["sender"]->socketId == $sender_socket_id && $cs["receiver"]->chatId == $data->receiver_id) {
                                    $receiver_client = $cs["receiver"];
                                    $sender_client = $cs["sender"];
                                }
                            }
                        }
                    }

                    if($receiver_client) {
                        $receiver_connection = null;
                        foreach ($this->connection_clients as $key => $cc) {
                            if($cc->resourceId == $receiver_client->socketId) {
                                $receiver_connection = $cc;
                            }
                        }
                        
                        if($receiver_connection) {
                            $body = [
                                "type" => "receive_message",
                                "scope" => "private",
                                "sender" => $sender_client,
                                "message" => $data->message
                            ];

                            $receiver_connection->send(json_encode($body));
                        }
                    }
                }
            }
        }
    
        public function onClose(ConnectionInterface $conn) {
            $this->connection_clients->detach($conn);

            $client = $this->findClientBy("socketId", $conn->resourceId);
            $this->clients->detach($client);

            // Deleta todos os registros de conjuntos onde o id $conn->resourceId esteja presente
            $indexes = [];
            foreach ($this->clients_sets as $type => $group) {
                foreach ($group as $key => $cs) {
                    if($cs["sender"]->socketId == $conn->resourceId || $cs["receiver"]->socketId == $conn->resourceId) {
                        unset($this->clients_sets[$type][$key]);
                    }
                }
            }

            // Reseta as chaves de cada nodo
            foreach ($this->clients_sets as $key => &$value) {
                $value = array_values($value);
            }

        }

        public function onError(ConnectionInterface $conn, \Exception $e) {
            $conn->close();
        }

        private function findClientBy($attr, $value) {
            if(count($this->clients) > 0) {
                $client = false;
                foreach($this->clients as $c) {
                    if($c->{$attr} == $value) {
                        $client = $c;
                        break;
                    }
                }
                return $client;
            }
            return false;
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