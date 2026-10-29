<?php

declare(strict_types=1);

namespace App\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * `MONTH(<data>)` in DQL: il mese (1–12) di una colonna data, come la funzione SQL omonima. DQL non la ha; serve alla
 * ricerca negli elenchi ("marzo" trova i record di marzo di qualunque anno). Registrata in `config/packages/doctrine.yaml`.
 *
 * "MONTH" "(" ArithmeticPrimary ")"
 */
final class Month extends FunctionNode
{
    private Node|string $date = '';

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->date = $parser->ArithmeticPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'MONTH(' . $sqlWalker->walkArithmeticPrimary($this->date) . ')';
    }
}
