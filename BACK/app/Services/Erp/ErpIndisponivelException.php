<?php

namespace App\Services\Erp;

use RuntimeException;

/** A API-ponte não respondeu (rede, 5xx, chave recusada) ou devolveu algo fora do contrato. */
class ErpIndisponivelException extends RuntimeException {}
