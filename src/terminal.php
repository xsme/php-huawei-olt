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

    /**
     * Przechodzienie między trybami w terminalu.
     *
     * @param string $mode tryb w ktory chcemy wejsc
     * @param [type] ...$arguments argumenty wymagane zaleznie od trybu
     * @return Terminal
     */
    public function setMode(string $mode, ...$arguments): self
    {
        if (strtolower($mode) === 'enable' and empty($arguments)) {
            $this->send("enable \n");
        }

        if (strtolower($mode) === 'config' and empty($arguments)) {
            $this->send("config \n");
        }

        if (strtolower($mode) === 'diagnose' and empty($arguments)) {
            $this->send("diagnose \n");
        }

        if (strtolower($mode) === 'btv' and empty($arguments)) {
            $this->send("btv \n");
        }

        if (strtolower($mode) === 'interface' 
                and count($arguments) === 2 
                and is_int($arguments[0]) 
                and is_int($arguments[1])) {
            $this->send(sprintf("interface gpon %u/%u \n", $arguments[0], $arguments[1]));
        }

        if (strtolower($mode) === 'multicast' 
                and count($arguments) === 1 
                and is_int($arguments[0])) {
            $this->send(sprintf("multicast-vlan %u \n", $arguments[0]));
        }

        return $this;
    }

    public function enter(): self
    {
        $this->send("\n");
        return $this;
    }

    /**
     * Wchodzenie w tryb upzywilejowany.
     *
     * @return Terminal
     */
    public function enable(): self
    {
        $this->setMode('enable');
        return $this;
    }

    /**
     * Wchodzenie w tryb konfiguracji.
     *
     * @param bool $mmiMode domyślnie ustawione "original-output"
     * @return Terminal
     */
    public function config(): self
    {
        $this->setMode("config");
        return $this;
    }

    /**
     * Wchodzenie w tryb diagnostyki.
     *
     * @return Terminal
     */
    public function diagnose(): self
    {
        $this->setMode("diagnose");
        return $this;
    }

    /**
     * Wchodzimy w tryb konfiguracji BTV.
     *
     * @return Terminal
     */
    public function btv(): self
    {
        $this->setMode('btv');
        return $this;
    }

    /**
     * Wchodzenie w tryb konfiguracji interfejsu/portu.
     *
     * @param integer $frame identyfikator obudowy, najczęsniej 0
     * @param integer $slot identyfiktor slotu w obudowie, liczba między 0-17
     * @return Terminal
     */
    public function interface(int $frame, int $slot): self
    {
        $this->setMode("interface", $frame, $slot);
        return $this;
    }

    /**
     * Wchodzimy w tryb konfiguracji multicast.
     *
     * @param integer $vlan numer vlan'u multicast dla ktorego wchodzimy w tryb konfiguracji
     * @return Terminal
     */
    public function multicastVlan(int $vlan): self
    {
        $this->setMode('multicast', $vlan);
        return $this;
    }

    /**
     * Ustawia odpoweidzi w terminalu na pełne, bez zwijania.
     *
     * @return Terminal
     */
    public function mmi(): self
    {
        $this->send("mmi-mode original-output \n");
        return $this;
    }

    /**
     * Wychodzenie/cofanie się w terminalu do poziomu nizej.
     *
     * @return Terminal
     */
    public function quit(): self
    {
        $this->send("quit \n");
        return $this;
    }

    /**
     * Potwierdzanie nowej karty liniowej na OLT.
     *
     * @param integer $frame identyfikator "chassie"
     * @param integer $board identyfikator karty w chassie
     * @return Terminal
     */
    public function boardConfirm(int $frame, int $board): self
    {
        $this->send(sprintf("board confirm %s/%s \n", $frame, $board));
        return $this;
    }

    /**
     * Włacznie Auto Find na danym porcie.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @return Terminal
     */
    public function portAutoFindEnable(int $port): self
    {
        $this->send(sprintf("port %s ont-auto-find enable", $port));
        return $this;
    }

    /**
     * Włączanie FEC na danym porcie.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @return Terminal
     */
    public function portFecEnable(int $port): self
    {
        $this->send(sprintf("port %s fec enable", $port));
        return $this;
    }

    /**
     * Włączanie alarmu optycznego na porcie.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $profileId identyfikator profilu alarmu optycznego, domyślnie 15
     * @return Terminal
     */
    public function portOpticalAlarmProfile(int $port, int $profileId = 15): self
    {
        $this->send(sprintf("port optical-alarm-profile %s profile-id %s", $port, $profileId));
        return $this;
    }

    /**
     * Wyszukiwanie nieautoryzowanych ONT.
     *
     * @param string $parameter do wyboru <all|conflict-check|time>
     * @return Terminal
     */
    public function autofind(string $parameter = 'all'): self
    {
        $this->send(sprintf("display ont autofind %s \n", $parameter));
        return $this;
    }

    /**
     * Wyświetlanie wersji urządzenia i modelu.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntVersion(int $port, int $ont): self
    {
        $this->send(sprintf("display ont version %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Wyświetlanie informacji o połączeniu.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntInfo(int $port, int $ont): self
    {
        $this->send(sprintf("display ont info %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Wyświetlanie informacji o sygnale optycznym na rzuądzeniu.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntOpticalInfo(int $port, int $ont): self
    {
        $this->send(sprintf("display ont optical-info %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Wyswietlanie informacji o portach WAN.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntWanInfo(int $port, int $ont): self
    {
        $this->send(sprintf("display ont wan-info %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Wyświetlanie informacji o statusach portów lan na urządzeniu.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntPortStateEthAll(int $port, int $ont): self
    {
        $this->send(sprintf("display ont port state %u %u eth-port all \n", $port, $ont));
        return $this;
    }

    /**
     * Wyświetlanie informacji o statuchach portów POTS na urządzniu.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntPortStatePotsAll(int $port, int $ont): self
    {
        $this->send(sprintf("display ont port state %u %u pots-port all \n", $port, $ont));
        return $this;
    }

    /**
     * Wyswietlanie informacji o WiFi.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntWlanInfo(int $port, int $ont): self
    {
        $this->send(sprintf("display ont wlan-info %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Wyswietlana status sieci WiFi.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntWlanStatus(int $port, int $ont): self
    {
        $this->send(sprintf("display ont wlan-status %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Wyświetlanie 10 ostatnich komunikatów urządzenia.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayOntRegisterInfo(int $port, int $ont): self
    {
        $this->send(sprintf("display ont register-info %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Wyświetlanie mac adresów na danym porcie lan na urządzeniu.
     *
     * @param integer $frame identyfikator obudowy, najczęsniej 0
     * @param integer $slot identyfiktor slotu w obudowie, liczba między 0-17
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @param integer $eth identyfikat inaczej numer portu Ethernet
     * @return Terminal
     */
    public function displayMacAddressOnt(int $frame, int $slot, int $port, int $ont): self
    {
        $this->send(sprintf("display mac-address ont %u/%u/%u %u\n", 
            $frame, $slot, $port, $ont));
        return $this;
    }

    /**
     * Wyświetlamy service port'y dla danego urządznia.
     *
     * @param integer $frame identyfikator obudowy, najczęsniej 0
     * @param integer $slot identyfiktor slotu w obudowie, liczba między 0-17
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @return Terminal
     */
    public function displayServicePortOnt(int $frame, int $slot, int $port, int $ont): self
    {
        $this->send(sprintf("display service-port port %u/%u/%u ont %u \n", 
            $frame, $slot, $port,  $ont));
        return $this;
    }

    /**
     * Autoryzowanie terminala abonenckiego na OLT.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param string $pon numer seryjny urządzenia składający się z 16 znaków
     * @param integer $lineProfile identyfikator profilu
     * @param integer $srvProfile identyfikat profilu
     * @param string $description opis urządzenia
     * @return Terminal
     */
    public function ontAdd(int $port, string $pon, int $lineProfile, int $srvProfile, string $description): self
    {
        $this->send(sprintf("ont add %u sn-auth %s omci ont-lineprofile-id %u ont-srvprofile-id %u desc %s \n", 
            $port, $pon, $lineProfile, $srvProfile, $description));
        return $this;
    }

    /**
     * Kasowanie ONU z OLT.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator ONT na danym porcie
     * @return Terminal
     */
    public function ontDelete(int $port, int $ont): self
    {
        $this->send(sprintf("ont delete %u %u \n", $port, $ont));
        return $this;
    }

    /**
     * Kasowanie service-port na OLT.
     *
     * @param integer $servicePortId index dla service-port
     * @return Terminal
     */
    public function undoServicePort(int $servicePortId): self
    {
        $this->send(sprintf("undo service-port %u \n", $servicePortId));
        return $this;
    }

    /**
     * Wyłącznie multicast'u na danym porcie lan na urządzniu.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @param integer $eth identyfikator portu lan na urządzniu, najczesniej 1-4
     * @return Terminal
     */
    public function ontPortIgmpForwardModeDisable(int $port, int $ont, int $eth = 1): self
    {
        $this->send(sprintf("ont port igmp-forward-mode %u %u eth %u disable \n", 
            $port, $ont, $eth));
        return $this;
    }

    /**
     * Ustawienie natywnego vlan'u na danym porcie.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @param integer $eth identyfikator portu lan na urządzniu, najczesniej 1-4
     * @param integer $vlan user-vlan pobierany z service port dla bridge
     * @param integer $priority dla inet jest 2, dla iptv jest 0
     * @return Terminal
     */
    public function ontPortNativeVlan(int $port, int $ont, int $eth, int $vlan, int $priority = 0): self
    {
        $this->send(sprintf("ont port native-vlan %u %u eth %s vlan %s priority %u \n", 
            $port, $ont, $eth, $vlan, $priority));
        return $this;
    }

    /**
     * Multicast dla dalengo portu lan na urządzniu na danym vlan'ie.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @param integer $eth identyfikator portu lan na urządzniu, najczesniej 1-4
     * @param integer $iptv vlan z telewizją
     * @return Terminal
     */
    public function ontPortMulticastForward(int $port, int $ont, int $eth, int $vlanIptv): self
    {
        $this->send(sprintf("ont port multicast-forward %u %u eth %u %u untag \n",
            $port, $ont, $eth, $vlanIptv));
        return $this;
    }

    /**
     * Włączanie optycznych alarmow na ONT.
     *
     * @param integer $port identyfikator portu w slocie, liczba między 0-15
     * @param integer $ont identyfikator urządzenia na porcie, liczba między 0-125
     * @param integer $profile identyfikator profilu, domyslnie 25
     * @return Terminal
     */
    public function ontOpticalAlarmProfile(int $port, int $ont, int $profileId = 25)
    {
        $this->send(sprintf("ont optical-alarm-profile %u %u profile-id %u \n",
            $port, $ont, $profileId));
        return $this;
    }

    /**
     * Dodajemy service port jako czlonka grupy multicast.
     *
     * @param integer $servicePortId identyfikato service port ktory jest przypisany do ONT
     * @return Terminal
     */
    public function igmpMulticastVlanMemberServicePort(int $servicePortId): self
    {
        $this->send(sprintf("igmp multicast-vlan member service-port %u \n", $servicePortId));
        return $this;
    }

    /**
     * Parametry autoryzacyjne grupy multicastowej dla service port.
     *
     * @param integer $servicePortId identyfikato service port ktory jest przypisany do ONT
     * @return Terminal
     */
    public function igmpUserAddServicePort(int $servicePortId): self
    {
        $this->send(sprintf("igmp user add service-port %u no-auth igmp-version v2-with-query \n\n", $servicePortId));
        return $this;
    }

    /**
     * Kasowanie service-port z IGMP od multicast.
     *
     * @param integer $servicePort
     * @return Terminal
     */
    public function igmpUserDeleteServicePort(int $servicePort): self
    {
        $this->send(sprintf("igmp user delete service-port %u \n"), $servicePort);
        return $this;
    }

    /**
     * Zwracamy output z terminala, z wylistowanymi serviceport'ami.
     *
     * @param string $servicePortFilter
     * @return Terminal
     */
    public function displayServicePortAll(string $servicePortFilter): self
    {
        $this->send(sprintf("display service-port all | %s \n", $servicePortFilter));
        return $this;
    }

    /**
     * Zwraca informacje o danym service-port.
     *
     * @param integer $servicePortId identyfikato service port ktory jest przypisany do ONT
     * @return Terminal
     */
    public function displayServicePort(int $servicePortId): self
    {
        $this->send(sprintf("display service-port %u \n", $servicePortId));
        return $this;
    }

    /**
     * Zwraca wszystkie inner-vlan o podanym numerze dla wszystkich service-port.
     *
     * @param integer $vlan
     * @return Terminal
     */
    public function displayServicePortInnerVlan(int $vlan, string $servicePortVlan): self
    {
        $this->send(sprintf("display service-port inner-vlan vlan %u | include %s \n", $vlan, $servicePortVlan));
        return $this;
    }
}