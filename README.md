# PTel
Yet another implementation of Telnet protocol written using PHP.
This class created && used for my daily use cases, so it can contains bugs. If you found them - open issue, or feel free to contact with me.

## Installation

### Composer

Inside your root folder with project run following command:

```bash
composer require "bosha/ptel": "dev-master"
```

Or manually add to your composer.json:

```json
"require": {
    "bosha/ptel": "dev-master"
}
```

And use it:

```php
<?php

require_once(__DIR__."/../vendor/autoload.php");

$telnet = new PTel\PTel();
```

### Manually

Navigate to location of your project, and run following commands:

```bash
git clone https://github.com/bosha/PTel.git
mv PTel PTel_orig
mv PTel_orig/src/PTel PTel
rm -rf PTel_orig
```

Add to your code:

```php
<?php

require_once("PTel/PTel.php");
require_once("PTel/SocketClientException.php");
require_once("PTel/TelnetException.php");

$telnet = new PTel();
```

After that - you can create new instance of class, and use it.

## Using PTel

### Using

After creating new instance, you need to connect to device and login:

```php
try {
  $telnet = new PTel();
  $telnet->connect("mysupercisco.hostname.com");
  $telnet->login("bosha", "mysupersecurepassword");
} catch (Exception $e) { print_r($e); }
```

After successful login - you can do basic stuff like send and receive information from device:

```php
$telnet->send("show version");
echo $telnet->recvAll();
```

Also some additional commands can be useful: 

```php
// Getting output of command:
$cisco_log = $telnet->getOutputOf("show logging");

// Searching for something in output stream to make application logic:
$telnet->send("copy running start");
if ($telnet->find("OK")) {
  echo "Configuration successful saved!"
}

// Some basic "expect" logic:
try {
  $telnet->send("enable");
  $telnet->expect('(P|p)ass((W|w)ord|)(:|)', "enablesecretpassword"); // Regular expressions supported
  if ($telnet->find("#")) {
    echo "Enable successful!"
  } else {
    echo "Looks like enable password are wrong!"
  }
} catch (Exception $e) {
    throw new BadPasswordException('Enable failed!');
}

// If you know that command execution can take a long - you can wait for some output from socket:
try {
  $telnet->send("copy running start");
  $telnet->waitReply(10); // Will not wait longer than 10 seconds
} catch (Exception $e) { print_r($e); }
// Or wait for some text. For example - prompt:
try {
  $telnet->send("copy running start");
  $telnet->waitFor($telnet->prompt); // $prompt - public variable with prompt
} catch (Exception $e) { print_r($e); }
```

Usually, prompt will be automatically cutted from device output after successful login.
Current prompt can be returned and changes if required:

```php
if ($telnet->getPrompt() !== ">#") {
  $telnet->setPrompt(">#");
}
```

Prompt is using to determine command execution end, so if you will use commands like getOutputOf - you need to be sure that prompt is properly configured.

Some additional telnet negotiation options can be also specified:

```php
// Terminal speed
$telnet->setTermSpeed(38000, 38000);

// Terminal type
$telnet->setTerm("xterm);
```

If device using some unusual page delimiters - they can be specified by changing $page_delimiter variable. New line character (carriage return) can be also changed by changing $retcarriage variable.

### Extending

PTel can be easily extended if you need some functionality which not implemented, or for rewriting exists methods to suits your needs:

```php
class Cisco_Telnet extends PTel
{

  public function login($user, $pass, $timeout = 10) {
    try {
      $this->expect('CustomUserNameRequest:', $user);
      $this->expect('CustomPasswordRequest:', $pass);
    } catch (Exception $e) {
      throw new Exception('Could not find password request. Login failed.');
    }

    $timestart = time();
    $buff = '';
    while (true) {
      $buff = $this->recvLine();
      $timerun = time() - $timestart;

      if (preg_match("/wrong!)/i", $buff)) {
        throw new Exception("Username or password wrong! Login failed");
      }

      if (preg_match("/]>>>/", $buff)) {
        break;
      }

      if ($timerun >= $maxtimeout) {
        throw new Exception("Could not get reply from device. Login failed.");
      }
    }

    $lines = explode("\n", $this->getBuffer());
    $prompt = array_slice($lines, -1);
    $this->prompt = $prompt[0];
    return true;
  }
  
  public function enable($enpass) {
    try {
      $this->send("enable");
      $this->expect('EnablePasswordRequest:', "enablesecretpassword");
      if ($telnet->find("#")) {
        $lines = explode("\n", $this->getBuffer());
        $prompt = array_slice($lines, -1);
        $this->prompt = $prompt[0] 
      } else {
        throw new Exception("Login fail [ bad password ] !");
      }
    } catch (Exception $e) {
      throw new BadPasswordException('Enable failed!');
    }
  }

}
```
