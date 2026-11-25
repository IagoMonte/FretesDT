<?php

namespace App\Services\Erp;

use RuntimeException;

/** Linha do ERP que não dá para espelhar (CNPJ vazio, nome ausente...): é pulada, logada e a execução termina PARCIAL. */
class RegistroInvalido extends RuntimeException {}
