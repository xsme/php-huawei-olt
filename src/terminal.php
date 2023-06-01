<?php

namespace xsme\HuaweiOlt;

use Exception;
use phpseclib3\Net\SSH2;

class Terminal
{
    /**
     * @var string
     */
    protected $ipAddress;

    /**
     * @var int
     */
    protected $port;

    /**
     * @var string
     */
    protected $login;

    /**
     * @var string
     */
    protected $password;

    /**
     * @var 
     */
    protected $connection;

    /**
     * @var int
     */
    protected $debug;

    /**
     * Konstruktor.
     *
     * @param string  $ipAddress adres ip do mngt urzadznia
     * @param string  $login login uzytkownika SSH
     * @param string  $password haslo uzytwkonika SSH
     * @param integer $port port do polaczaenia ssh, domyslnie 22
     * @param bool    $debug jezeli jest wlaczony komendy nie sa wysylane do OLT tylko wyswietlane
     * @param integer $timeOut timeout dla polaczenia SSH, wylaczamy podajac false lub 0 
     * @param string  $socksIp adres serwera SOCKS jezeli uzywamy proxy do polaczania SSH
     * @param integer $socksPort port serwera SOCKS jezeli uzywamy proxy do polaczania SSH
     * @return void
     */
    public function __construct(string $ipAddress, string $login, string $password, int $port = 22, bool $debug = false, int $timeOut = 2, string $socksIp = null, int $socksPort = null) {
        $this->ipAddress  = $ipAddress;
        $this->login      = $login;
        $this->port       = $port;
        $this->password   = $password;
        $this->debug      = $debug;
        $this->connection = (ip2long($socksIp) !== false) 
            ? new SSH2($this->socksConnection($socksIp, $socksPort)) 
            : new SSH2($ipAddress, $port);

        $this->connection->setTimeout($timeOut);
    }

    /**
     * Inicjowanie połączenia przez SOCKS5
     *
     * @param string $socksIp adres serwera proxy SOCKS
     * @param integer $socksPort port serwera proxy SOCKS
     * @return resource|false
     */
    private function socksConnection(string $socksIp, int $socksPort)
    {
        $fsock = fsockopen($socksIp, $socksPort, $errNo, $errStr, 1);
        if (!$fsock) {
            throw new Exception($errStr);
        }
        $port = pack('n', $this->port);
        $address = chr(strlen($this->ipAddress)) . $this->ipAddress;
        $request = "\5\1\0";
        if (fwrite($fsock, $request) != strlen($request)) {
            throw new \Exception('Premature termination');
        }

        $response = fread($fsock, 2);
        if ($response != "\5\0") {
            throw new \Exception('Unsupported protocol or unsupported method');
        }

        $request = "\5\1\0\3$address$port";
        if (fwrite($fsock, $request) != strlen($request)) {
            throw new \Exception('Premature termination');
        }

        $response = fread($fsock, strlen($address) + 6);
        if (substr($response, 0, 2) != "\5\0") {
        echo bin2hex($response) . "\n";
            throw new \Exception("Unsupported protocol or connection refused");
        }
        return $fsock;
    }

    /**
     * Otwarcie sesji terminala.
     *
     * @return Terminal
     */
    public function connect(): self
    {
        if (!$this->connection->login($this->login, $this->password)) {
            exit('Login failed!');
        }
        $this->connection->read(); // czyszczenie motd po zalogowaniu
        return $this;
    }

    /**
     * Zamkniecie sesji konsoli.
     *
     * @return Terminal
     */
    public function disconnect(): self
    {
        $this->connection->disconnect();
        return $this;
    }

    /**
     * Wysyłanie komendy RAW do terminala.
     * Jezeli jest włączony debug, to komenda nie 
     * zostanie wysłana, będzie wyświetlona jako
     * output wywołanej funkcji.
     *
     * @param string $command komenda do wpisania w wiersz poleceń
     * @return Terminal
     */
    public function send($command = ''): self
    {
        $this->connection->write($command."\n");
        if ($this->debug) {
            print_r("[Debug] $command");
        }
        return $this;
    }

    /**
     * Odczytywanie informacji z terminala.
     * Jezeli jest włączony debug, to komenda nie 
     * zostanie wysłana, będzie wyświetlona 
     * informacja o wywołanej funkcji.
     *
     * @return string
     */
    public function read(): string
    {
        return $this->connection->read();
    }
}