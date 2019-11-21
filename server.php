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
                            "user1" => $sender,
                            "user2" => $receiver
                        ];
                    }
                } else if($data->scope == "group") {
                    $group_id = $data->group_id;
                    $users = explode(",", $data->users);

                    $index_group = null;
                    if(isset($this->clients_sets["group"])) {
                        foreach ($this->clients_sets["group"] as $key => $group) {
                            if($group["group_id"] == $group_id) {
                                $index_group = $key;
                                break;
                            }
                        }
                    }

                    if(is_null($index_group)) {
                        $handle = [
                            "group_id" => $group_id,
                            "users" => []
                        ];
                        foreach ($users as $key => $user_id) {
                            $user = $this->findClientBy("chatId", $user_id);
                            if($user) {
                                $handle["users"][] = $user;
                            }
                        }
    
                        if(count($handle) > 0) {
                            $this->clients_sets["group"][] = $handle;
                        }
                    } else {
                        $handle = $this->clients_sets["group"][$index_group];

                        foreach ($users as $key => $user_id) {
                            $user_in_group = false;
                            $user_index = null;
                            foreach ($handle["users"] as $key => $user) {
                                if($user->chatId == $user_id) {
                                    $user_in_group = true;
                                    $user_index = $key;
                                    break;
                                }
                            }

                            if($user_in_group) {
                                unset($handle["users"][$user_index]);
                            }
                            $user = $this->findClientBy("chatId", $user_id);
                            if($user) {
                                $handle["users"][] = $user;
                            }
                        }

                        $handle["users"] = array_values($handle["users"]);

                        $this->clients_sets["group"][$index_group] = $handle;
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
                                if($cs["user1"]->socketId == $sender_socket_id && $cs["user2"]->chatId == $data->receiver_id) {
                                    $receiver_client = $cs["user2"];
                                    $sender_client = $cs["user1"];
                                } else if($cs["user1"]->chatId == $data->receiver_id && $cs["user2"]->socketId == $sender_socket_id) {
                                    $receiver_client = $cs["user1"];
                                    $sender_client = $cs["user2"];
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
                } else if($data->scope == "group") {
                    $group_id = $data->group_id;

                    $users_to_send = [];
                    foreach ($this->clients_sets["group"] as $key => $group) {
                        if($group["group_id"] == $group_id) {
                            foreach ($this->clients_sets["group"][$key]["users"] as $i => $user) {
                                if($user->chatId != $data->sender_chat_id) {
                                    $users_to_send[] = $user;
                                }
                            }
                        }
                    }

                    if(count($users_to_send) > 0) {
                        $connection_users = [];
                        
                        foreach ($users_to_send as $key => $user) {
                            $u = $this->findConnectionClientBy("resourceId", $user->socketId);

                            if($u) {
                                $connection_users[] = $u;
                            }
                        }

                        if(count($connection_users) > 0) {
                            $sender_client = $this->findClientBy("chatId", $data->sender_chat_id);

                            $body = [
                                "type" => "receive_message",
                                "scope" => "group",
                                "group_id" => $group_id,
                                "sender" => $sender_client,
                                "message" => $data->message
                            ];

                            foreach ($connection_users as $key => $conn) {
                                $conn->send(json_encode($body));
                            }
                        }
                    }
                }
            } else if($type == "friend_request") {
                $request_user = $this->findClientBy("chatId", $data->request_user);
                $user_to_add = $this->findClientBy("chatId", $data->user_to_add);

                if($user_to_add) {
                    $connection = $this->findConnectionClientBy("resourceId", $user_to_add->socketId);

                    if($connection) {
                        $body = [
                            "type" => "friend_request",
                            "request_user" => $request_user,
                            "user_to_add" => $user_to_add
                        ];

                        $connection->send(json_encode($body));
                    }
                }
            } else if($type == "accept_friend_request") {
                $request_user = $this->findClientBy("chatId", $data->request_user);
                $user_to_add = $this->findClientBy("chatId", $data->user_to_add);

                if($request_user) {
                    $connection = $this->findConnectionClientBy("resourceId", $request_user->socketId);

                    if($connection) {
                        $body = [
                            "type" => "accept_friend_request",
                            "request_user" => $request_user,
                            "user_to_add" => $user_to_add
                        ];

                        $user_to_add_conn = $this->findConnectionClientBy("resourceId", $user_to_add->socketId);
                        if($user_to_add_conn) {
                            $user_to_add_conn->send(json_encode($body));
                        }

                        $connection->send(json_encode($body));
                    }
                }
            } else if($type == "reject_friend_request") {
                $request_user = $this->findClientBy("chatId", $data->request_user);
                $user_to_add = $this->findClientBy("chatId", $data->user_to_add);

                if($request_user) {
                    $connection = $this->findConnectionClientBy("resourceId", $request_user->socketId);

                    if($connection) {
                        $body = [
                            "type" => "reject_friend_request",
                            "request_user" => $request_user,
                            "user_to_add" => $user_to_add
                        ];

                        $connection->send(json_encode($body));
                    }
                }
            } else if($type == "remove_friend") {
                $request_user = $this->findClientBy("chatId", $data->request_user);
                $user_to_add = $this->findClientBy("chatId", $data->user_to_add);

                if($request_user) {
                    $connection = $this->findConnectionClientBy("resourceId", $request_user->socketId);

                    if($connection) {
                        $body = [
                            "type" => "remove_friend",
                            "request_user" => $request_user,
                            "user_to_add" => $user_to_add
                        ];

                        $user_to_add_conn = $this->findConnectionClientBy("resourceId", $user_to_add->socketId);
                        if($user_to_add_conn) {
                            $user_to_add_conn->send(json_encode($body));
                        }

                        $connection->send(json_encode($body));
                    }
                }
            } else if($type == "initial_online_status") {
                $request_user = $this->findClientBy("chatId", $data->request_user);
                $connection = $this->findConnectionClientBy("resourceId", $request_user->socketId);

                if($connection) {
                    $response = [];
                    foreach ($data->users as $key => $user) {
                        $client = $this->findClientBy("chatId", $user);
                        if($client) {
                            $check = $this->findConnectionClientBy("resourceId", $client->socketId);
                            $response[$user] = ($check != false);
                        } else {
                            $response[$user] = false;
                        }
                    }

                    $body = [
                        "type" => "initial_online_status",
                        "users" => $response
                    ];

                    $connection->send(json_encode($body));
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
                if($type == "private") {
                    foreach ($group as $key => $cs) {
                        foreach ($cs as $i => $c) {
                            if($c->socketId == $conn->resourceId) {
                                unset($this->clients_sets[$type][$key]);
                            }
                        }
                    }
                } else if($type == "group") {
                    foreach ($group as $key => $data) {
                        foreach ($data["users"] as $i => $user) {
                            if($user->socketId == $conn->resourceId) {
                                unset($this->clients_sets[$type][$key]["users"][$i]);
                            }
                        }
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

        private function findConnectionClientBy($attr, $value) {
            if(count($this->connection_clients) > 0) {
                $connection = false;
                foreach($this->connection_clients as $c) {
                    if($c->{$attr} == $value) {
                        $connection = $c;
                        break;
                    }
                }
                return $connection;
            }
            return false;
        }

        private function duplicateInArray($array, $key, $value) {
            $flag = false;
            foreach ($array as $k => $v) {
                if($v->{$key} == $value) {
                    $flag = true;
                    break;
                }
            }
            return $flag;
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