<?php

/**
 *    Copyright (C) 2023-2024 Cedrik Pischem
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */

namespace OPNsense\Caddy;

use OPNsense\Base\BaseModel;
use OPNsense\Base\Messages\Message;
use OPNsense\Core\Config;

class Caddy extends BaseModel
{
    // Check domain-port combinations
    private function checkForUniquePortCombos($messages)
    {
        $combos = [];
        foreach ($this->reverseproxy->reverse->iterateItems() as $item) {
            $key = $item->__reference;
            $fromDomain = (string) $item->FromDomain;
            $fromPort = (string) $item->FromPort;

            if ($fromPort === '') {
                $defaultPorts = ['80', '443'];
            } else {
                $defaultPorts = [$fromPort];
            }

            foreach ($defaultPorts as $port) {
                $comboKey = $fromDomain . ':' . $port;

                if (isset($combos[$comboKey])) {
                    $messages->appendMessage(new Message(
                        sprintf(
                            gettext(
                                'Duplicate entry: The combination of %s and port %s is already used. ' .
                                'Each combination of domain and port must be unique.'
                            ),
                            $fromDomain,
                            $port
                        ),
                        $key . ".FromDomain"
                    ));
                } else {
                    $combos[$comboKey] = true;
                }
            }
        }
    }

    // Get the current OPNsense WebGUI ports and check for conflicts with Caddy
    private function getWebGuiPorts()
    {
        $webgui = Config::getInstance()->object()->system->webgui ?? null;
        $webGuiPorts = [];

        // Only add ports to array if no specific interfaces for the WebGUI are set
        if (!empty($webgui) && empty((string)$webgui->interfaces)) {
            // Add port 443 if no specific port is set, otherwise set custom webgui port
            $webGuiPorts[] = !empty($webgui->port) ? (string)$webgui->port : '443';

            // Add port 80 if HTTP redirect is not explicitly disabled
            if (empty((string)$webgui->disablehttpredirect)) {
                $webGuiPorts[] = '80';
            }
        }

        return $webGuiPorts;
    }

    private function checkWebGuiSettings($messages)
    {
        // Get custom caddy ports if set. If empty, default to 80 and 443.
        $httpPort = !empty((string)$this->general->HttpPort) ? (string)$this->general->HttpPort : '80';
        $httpsPort = !empty((string)$this->general->HttpsPort) ? (string)$this->general->HttpsPort : '443';
        $tlsAutoHttpsSetting = (string)$this->general->TlsAutoHttps;

        // Check for conflicts
        $overlap = array_intersect($this->getWebGuiPorts(), [$httpPort, $httpsPort]);

        if (!empty($overlap) && $tlsAutoHttpsSetting !== 'off') {
            $portOverlap = implode(', ', $overlap);
            $messages->appendMessage(new Message(
                sprintf(
                    gettext(
                        'To use "Auto HTTPS", resolve these conflicting ports %s ' .
                        'that are currently configured for the OPNsense WebGUI. ' .
                        'Go to "System - Settings - Administration". ' .
                        'To release port 80, enable "Disable web GUI redirect rule". ' .
                        'To release port %s, change "TCP port" to a non-standard port, ' .
                        'e.g., 8443.'
                    ),
                    $portOverlap,
                    $httpsPort
                ),
                "general.TlsAutoHttps"
            ));
        }
    }

    // Prevent the usage of conflicting options when TLS is deactivated for a Domain
    private function checkDisableTlsConflicts($messages)
    {
        foreach ($this->reverseproxy->reverse->iterateItems() as $item) {
            if ($item->isFieldChanged()) {
                if ((string) $item->DisableTls === '1') {
                    $conflictChecks = [
                        'DnsChallenge' => (string) $item->DnsChallenge === '1',
                        'AcmePassthrough' => !empty((string) $item->AcmePassthrough),
                        'CustomCertificate' => !empty((string) $item->CustomCertificate)
                    ];

                    $conflictFields = array_keys(array_filter($conflictChecks));

                    if (!empty($conflictFields)) {
                        $messages->appendMessage(new Message(
                            gettext(
                                'TLS cannot be disabled if one of the following options are used: ' .
                                '"DNS-01 Challenge", "HTTP-01 Challenge Redirection" and "Custom Certificate"'
                            ),
                            $item->__reference . ".DisableTls"
                        ));
                    }
                }
            }
        }
    }

    /**
     * Check that when Superuser is disabled, all ports are 1024 and above.
     * In General settings where this triggers, a validation dialog will show the hidden validation of the domain ports.
     * The default HTTP and HTTPS ports are not allowed to be empty, since then they are 80 and 443.
     * Domain ports are allowed to be empty, since then they have the same value as the HTTP and HTTPS default ports.
     * Any value that is below 1024 will trigger the validation.
     */
    private function checkSuperuserPorts($messages)
    {
        if ((string)$this->general->DisableSuperuser === '1') {
            $httpPort = !empty((string)$this->general->HttpPort) ? (string)$this->general->HttpPort : 80;
            $httpsPort = !empty((string)$this->general->HttpsPort) ? (string)$this->general->HttpsPort : 443;

            // Check default HTTP port
            if ($httpPort < 1024) {
                $messages->appendMessage(new Message(
                    gettext(
                        'www user is active, HTTP port must not be empty and must be 1024 or above.'
                    ),
                    "general.HttpPort"
                ));
            }

            // Check default HTTPS port
            if ($httpsPort < 1024) {
                $messages->appendMessage(new Message(
                    gettext(
                        'www user is active, HTTPS port must not be empty and must be 1024 or above.'
                    ),
                    "general.HttpsPort"
                ));
            }

            // Check ports under domain configurations
            foreach ($this->reverseproxy->reverse->iterateItems() as $item) {
                $fromPort = !empty((string)$item->FromPort) ? (string)$item->FromPort : null;

                if ($fromPort !== null && $fromPort < 1024) {
                    $messages->appendMessage(new Message(
                        gettext(
                            'www user is active, port must be empty or must be 1024 or above.'
                        ),
                        $item->__reference . ".FromPort"
                    ));
                    $messages->appendMessage(new Message(
                        gettext(
                            'Ports in "Reverse Proxy - Domains" must be empty or must be 1024 or above.'
                        ),
                        "general.DisableSuperuser"
                    ));
                }
            }

            foreach ($this->reverseproxy->layer4->iterateItems() as $item) {
                $fromPort = !empty((string)$item->FromPort) ? (string)$item->FromPort : null;

                if ($fromPort !== null && $fromPort < 1024) {
                    $messages->appendMessage(new Message(
                        gettext(
                            'www user is active, port must be empty or must be 1024 or above.'
                        ),
                        $item->__reference . ".FromPort"
                    ));
                    $messages->appendMessage(new Message(
                        gettext(
                            'Ports in "Reverse Proxy - Layer4 Routes" must be empty or must be 1024 or above.'
                        ),
                        "general.DisableSuperuser"
                    ));
                }
            }
        }
    }

    private function checkLayer4Matchers($messages)
    {
        foreach ($this->reverseproxy->layer4->iterateItems() as $item) {
            if ($item->isFieldChanged()) {
                $key = $item->__reference;
                if (in_array((string)$item->Matchers, ['httphost', 'tlssni']) && empty((string)$item->FromDomain)) {
                    $messages->appendMessage(new Message(
                        sprintf(
                            gettext(
                                'When "%s" matcher is selected, domain is required.'
                            ),
                            $item->Matchers
                        ),
                        $key . ".FromDomain"
                    ));
                } elseif (
                        !in_array((string)$item->Matchers, ['httphost', 'tlssni']) &&
                        (
                            !empty((string)$item->FromDomain) &&
                            (string)$item->FromDomain != '*'
                        )
                ) {
                    $messages->appendMessage(new Message(
                        sprintf(
                            gettext(
                                'When "%s" matcher is selected, domain must be empty or *.'
                            ),
                            $item->Matchers
                        ),
                        $key . ".FromDomain"
                    ));
                }

                if ((string)$item->Type === 'global' && empty((string)$item->FromPort)) {
                    $messages->appendMessage(new Message(
                        sprintf(
                            gettext(
                                'When routing type is "%s", port is required.'
                            ),
                            $item->Type
                        ),
                        $key . ".FromPort"
                    ));
                } elseif ((string)$item->Type !== 'global' && !empty((string)$item->FromPort)) {
                    $messages->appendMessage(new Message(
                        sprintf(
                            gettext(
                                'When routing type is "%s", port must be empty.'
                            ),
                            $item->Type
                        ),
                        $key . ".FromPort"
                    ));
                }

                if ((string)$item->Type !== 'global' && ((string)$item->Protocol !== 'tcp')) {
                    $messages->appendMessage(new Message(
                        sprintf(
                            gettext(
                                'When routing type is "%s", protocol must be TCP.'
                            ),
                            $item->Type
                        ),
                        $key . ".Protocol"
                    ));
                }

                if (
                    (string)$item->Type !== 'global' &&
                    (
                        (string)$item->Matchers == 'tls' ||
                        (string)$item->Matchers == 'http'
                    )
                ) {
                    $messages->appendMessage(new Message(
                        sprintf(
                            gettext(
                                'When routing type is "%s", matchers "HTTP" or "TLS" cannot be chosen.'
                            ),
                            $item->Type
                        ),
                        $key . ".Matchers"
                    ));
                }
            }
        }
    }

    // Perform the actual validation
    public function performValidation($validateFullModel = false)
    {
        $messages = parent::performValidation($validateFullModel);

        $this->checkForUniquePortCombos($messages);
        $this->checkWebGuiSettings($messages);
        $this->checkDisableTlsConflicts($messages);
        $this->checkSuperuserPorts($messages);
        $this->checkLayer4Matchers($messages);

        return $messages;
    }
}
