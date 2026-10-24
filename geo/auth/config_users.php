<?php

if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    http_response_code(403);
    exit('Acesso proibido.');
}

return [
    'admin' => [
        'nome'   => 'Administrador',
        'senha'  => '$2y$10$a5OcCABFIE9fUEdoYjdtfuopszZQoJCTnYD9mfIzCfB8QhrsNDsXu',
        'perfil' => 'admin'
    ],

    'michael.samson' => [
        'nome'   => 'Michael Samson',
        'senha'  => '$2y$10$AwuhRtqLDCjrpNhqnYlS8OhBCFpIPBkql2Vz4uZfMnHHciv2CnBTS',
        'perfil' => 'michael.samson'
    ],

];
