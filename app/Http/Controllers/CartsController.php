<?php
namespace App\Http\Controllers;

use App\Http\Requests\CartDiscountRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Request;
use Money\Money;
use Money\MoneyFormatter;
use Money\MoneyParser;

class CartsController extends Controller
{
    public function calculateDiscount(
        CartDiscountRequest $request,
        MoneyParser $moneyParser,
        MoneyFormatter $moneyFormatter
    ): JsonResponse {
        // Your logic goes here, use the code below just as a guidance.
        // You can do whatever you want with this code, even delete it.
        // Think about responsibilities, testing and code clarity.

        // DUVIDAS: se um produto é promocional e sua categoria também é? vale o menor desconto OK
        // DUVIDAS: se no desconto por categoria houver uma lista de produtos com varias categorias
        //          em desconto, o desconto aplicado será por cada categoria seguindo a regra de
        //          dar 40% de desconto no menor valor de item daquela categoria, assumindo que será
        //          1 compra no total, esses descontos se acumularão até formar o valor total do carrinho
        //          para só entao passar para outras regras... Assim, se tivermos 3 categorias em promocao
        //          esses descontos serão somados pois cada categoria permite seu desconto individual, mas
        //          a regra de não cumulativo é aplicada apenas aos "itens de regras" definidos neste teste
        // SUGESTAO DE CORRECAO PRO TESTE:
        // - a regra de assumir que 404 indicar um novo cliente assumindo que a API retornará isso
        //   porque o retorno de 404 está apos a validacao de email, e um erro pois a API poderia
        //   ter caído, estar fora, algum serviço não respondendo ou até um ataque hacker, fornecendo
        //   descontos para todos os carrinhos

        $strategy = 'none';
        $subtotal = Money::BRL(0);
        $hasItemPromotional = false;
        $promotionalItems = [];
        $hasCategoryPromotional = false;
        $userEmail = $request->get('userEmail');

        $products = $request->get('products');
        foreach ($products as $product) {
            $unitPrice = $moneyParser->parse($product['unitPrice'], 'BRL');
            $amount = $unitPrice->multiply($product['quantity']);
            $subtotal = $subtotal->add($amount);
            if (ProductsController::isPromotional($product['id'])) {
                $hasItemPromotional = true;
                $promotionalItems[] = $product;
            }
            if (ProductsController::hasCategoryPromotional($product['categoryId'])) {
                $hasCategoryPromotional = true;
            }
        }

        $discount = Money::BRL(0);

        $discountRules = [
            // Esses descontos não são cumulativos, então somente o maior desconto para o
            // cliente deverá ser considerado. É necessário indicar na API qual foi o desconto aplicado.

            1 => [
                // Oferecemos 15% de desconto para carrinhos a partir de R$3000,00.
                'description' => 'Desconto de porcentagem com base no valor total',
                'rule' => $this->rule1(floatval($moneyFormatter->format($subtotal))),
            ],

            2 => [
                // A cada duas unidades compradas de certos produtos, a terceira unidade
                // será gratuita, ou seja leve 3, pague 2. Isso vale para múltiplos também.
                // Levando 9 unidades por exemplo, o cliente pagará somente 6 unidades.
                // Os produtos que participam dessa promoção podem ser consultados através da config api.php.
                'description' => 'Desconto de quantidade do mesmo item',
                'rule' => $this->rule2($hasItemPromotional, $promotionalItems, $moneyParser, $moneyFormatter),
            ],

            3 => [
                // Ao comprar dois ou mais produtos diferentes de uma determinada categoria,
                // somente uma unidade do produto mais barato dessa categoria deve receber 40%
                // de desconto. As categorias determinadas podem ser consultadas através da config api.php.
                'description' => 'Desconto de porcentagem no item mais barato de uma mesma categoria',
                'rule' => $this->rule3($hasCategoryPromotional, $products, $moneyParser, $moneyFormatter),
            ],

            4 => [
                // Um usuário que seja colaborador tem 20% de desconto no total do carrinho.
                'description' => 'Desconto de porcentagem para colaboradores',
                'rule' => $this->rule4($userEmail),
            ],

            5 => [
                // Caso seja a primeira compra, o usuário tem um desconto fixo de R$25,00 em compras
                // acima de R$50,00. A rota /user/{email} retorna 404 caso o usuário não exista e se ele
                // não existe ele é considerado um novo usuário.
                'description' => 'Desconto em valor para novos usuários',
                'rule' => $this->rule5($userEmail),
            ],

        ];

        $rulesMatched = [];
        $minorTotalValue = Money::BRL(0);
        $discountTotalValue = Money::BRL(0);
        $majorDiscountTotalValue = Money::BRL(0);
        $minorStrategy = 0;
        $percentualDiscount = Money::BRL(0);
        $valueDiscount = Money::BRL(0);
        foreach ($discountRules as $ruleId => $ruleApplied) {
            $ruleAppliedBool = (is_array($ruleApplied['rule'])) ? $ruleApplied['rule']['applied'] | false : false;
            if ($ruleAppliedBool) {
                if (!array_key_exists($ruleId, $rulesMatched)) {
                    $rulesMatched[$ruleId] = [];
                }
                // filtro para a ultima condicao (5) validar se o valor total do carrinho é maior que 50
                if (5 == $ruleId && $subtotal->lessThan($moneyParser->parse('50.01', 'BRL'))) {
                    continue;
                }
                $rulesMatched[$ruleId] = [
                    'rules' => $ruleApplied,
                ];
                $percentualDiscount = (is_array($ruleApplied['rule'])) ? $ruleApplied['rule']['discountPercent'] : Money::BRL(0);
                $valueDiscount = (is_array($ruleApplied['rule'])) ? $ruleApplied['rule']['discountValue'] : Money::BRL(0);

                if (Money::BRL(0)->lessThan($percentualDiscount)) {
                    $discountTotalValue = $subtotal->multiply($percentualDiscount->getAmount())->divide(100);
                    $rulesMatched[$ruleId]['subtotal'] = $subtotal->subtract($discountTotalValue);
                } else {
                    $discountTotalValue = $valueDiscount;
                    $rulesMatched[$ruleId]['subtotal'] = $subtotal->subtract($discountTotalValue);
                }
                $discountRules[$ruleId]['final_discount'] = $moneyFormatter->format($discountTotalValue);
                $discountRules[$ruleId]['subtotal_with_discount'] = $moneyFormatter->format($rulesMatched[$ruleId]['subtotal']);
                if (floatval($moneyFormatter->format($rulesMatched[$ruleId]['subtotal'])) > 0 &&
                    (
                        $minorTotalValue->equals(Money::BRL(0)) ||
                        $minorTotalValue->greaterThan($rulesMatched[$ruleId]['subtotal'])
                    )
                ) {
                    $minorTotalValue = $rulesMatched[$ruleId]['subtotal'];
                    $minorStrategy = $ruleId;
                    $majorDiscountTotalValue = $discountTotalValue;
                }
            }
        }

        $discount = $majorDiscountTotalValue;
        $total = $subtotal->subtract($discount);
        $strategyNames = [
            1 => 'above-3000',
            2 => 'take-3-pay-2',
            3 => 'same-category',
            4 => 'employee',
            5 => 'new-user',
        ];
        $strategy = (0 == $minorStrategy) ? 'none' : $strategyNames[$minorStrategy];

        $isDebug = (bool) $request->get('debug') | false;
        $finalResponse = [
            'message' => 'Success.',
            'data' => [
                'subtotal' => $moneyFormatter->format($subtotal),
                'discount' => $moneyFormatter->format($discount),
                'total' => $moneyFormatter->format($total),
                'strategy' => $strategy,
            ],
        ];
        if ($isDebug) {
            $finalResponse['debug'] = [
                'valor final com desconto' => $moneyFormatter->format($minorTotalValue),
                'estrategia' => $minorStrategy.' - '.((array_key_exists($minorStrategy, $discountRules)) ? $discountRules[$minorStrategy]['description'] : 'NENHUMA'),
                'total de desconto' => $moneyFormatter->format($majorDiscountTotalValue),
                'valor raw desconto' => [
                    'percentualDiscount' => $percentualDiscount,
                    'valueDiscount' => $valueDiscount,
                ],
            ];
            $finalResponse['rulesPossibles'] = $discountRules;
        }
        return new JsonResponse(
            $finalResponse
        );
    }

    /**
     * Apply Rule 1 - Oferecemos 15% de desconto para carrinhos a partir de R$3000,00.
     *
     * @param float $subtotal The subtotal of the cart.
     *
     * @return mixed[] The result of the rule application, containing:
     *               - applied (bool): Whether the rule was applied.
     *               - discountPercent (float): The percentage discount applied.
     *               - discountValue (float): The discount value applied.
     */
    public function rule1(
        float $subtotal
    ): ?array {
        if ($subtotal >= 3000) {
            return [
                'applied' => true,
                'discountPercent' => Money::BRL(15),
                'discountValue' => Money::BRL(0),
            ];
        } else {
            return [
                'applied' => false,
                'discountPercent' => Money::BRL(0),
                'discountValue' => Money::BRL(0),
            ];
        }
    }

    /**
     * Apply Rule 2 - A cada duas unidades compradas de certos produtos, a terceira unidade
     *                será gratuita, ou seja leve 3, pague 2. Isso vale para múltiplos também.
     *                Levando 9 unidades por exemplo, o cliente pagará somente 6 unidades.
     *                Os produtos que participam dessa promoção podem ser consultados através da config api.php.
     *
     * @param bool           $hasItemPromotional Whether there are promotional items in the cart.
     * @param array<array>   $products           The array of products in the cart.
     * @param MoneyParser    $moneyParser        The money parser instance.
     * @param MoneyFormatter $moneyFormatter     The money formatter instance.
     *
     * @return mixed[] The result of the rule application, containing:
     *               - applied (bool): Whether the rule was applied.
     *               - discountPercent (float): The percentage discount applied.
     *               - discountValue (float): The discount value applied.
     */
    public function rule2(
        bool $hasItemPromotional,
        array $products,
        MoneyParser $moneyParser,
        MoneyFormatter $moneyFormatter
    ): ?array {
        if ($hasItemPromotional) {
            $discountTotal = Money::BRL(0);
            foreach ($products as $product) {
                if (ProductsController::isPromotional($product['id'])) {
                    for ($i = 0; $i < $product['quantity']; $i++) {
                        if (($i + 1) % 3 == 0) {
                            // Aplica o desconto a cada 3 produtos
                            $discountTotal = $discountTotal->add($moneyParser->parse($product['unitPrice'], 'BRL'));
                        }
                    }
                }
            }
            if ($discountTotal->equals(Money::BRL(0))) {
                // possui produtos na promocao mas nao em quantidade suficiente
                return [
                    'applied' => false,
                    'discountPercent' => Money::BRL(0),
                    'discountValue' => Money::BRL(0),
                ];
            } else {
                return [
                    'applied' => true,
                    'discountPercent' => Money::BRL(0),
                    'discountValue' => $discountTotal,
                ];
            }
        } else {
            return [
                'applied' => false,
                'discountPercent' => Money::BRL(0),
                'discountValue' => Money::BRL(0),
            ];
        }
    }

    /**
     * Apply Rule 3 - Ao comprar dois ou mais produtos diferentes de uma determinada categoria,
     *                somente uma unidade do produto mais barato dessa categoria deve receber 40%
     *                de desconto. As categorias determinadas podem ser consultadas através da config api.php.
     *
     * @param bool           $hasCategoryPromotional Whether there are promotional categories in the cart.
     * @param array<array>   $products               The array of products in the cart.
     * @param MoneyParser    $moneyParser            The money parser instance.
     * @param MoneyFormatter $moneyFormatter         The money formatter instance.
     *
     * @return mixed[] The result of the rule application, containing:
     *               - applied (bool): Whether the rule was applied.
     *               - discountPercent (float): The percentage discount applied.
     *               - discountValue (float): The discount value applied.
     */
    public function rule3(
        bool $hasCategoryPromotional,
        array $products,
        MoneyParser $moneyParser,
        MoneyFormatter $moneyFormatter
    ): ?array {
        if ($hasCategoryPromotional) {
            $discountTotal = Money::BRL(0);

            $productsPerCategory = [];
            foreach ($products as $product) {
                if (ProductsController::hasCategoryPromotional($product['categoryId'])) {
                    if (!array_key_exists($product['categoryId'], $productsPerCategory)) {
                        $productsPerCategory[$product['categoryId']] = [];
                    }
                    $productsPerCategory[$product['categoryId']][] = $product;
                }
            }

            $minorPriceProduct = Money::BRL(0);
            // $idMinorPrice='';
            foreach ($productsPerCategory as $category) {
                foreach ($category as $product) {
                    if ((float) $product['unitPrice'] > 0 &&
                        (
                            $minorPriceProduct->equals(Money::BRL(0)) ||
                            $minorPriceProduct->greaterThan($moneyParser->parse($product['unitPrice'], 'BRL'))
                        )
                    ) {
                        $minorPriceProduct = $moneyParser->parse($product['unitPrice'], 'BRL');
                        // $idMinorPrice = $product['id'];
                    }
                }
                // Aplica o desconto do menor valor de produto (40% do valor dele)
                $discountTotal = $discountTotal->add($minorPriceProduct->multiply(.4, Money::ROUND_DOWN));
                // observa-se que será somado à outras categorias que/se houverem
                // o $idMinorPrice poderá ser usado para descriminar o item se necessário
            }
            return [
                'applied' => true,
                'discountPercent' => Money::BRL(0),
                'discountValue' => $discountTotal,
            ];
        } else {
            return [
                'applied' => false,
                'discountPercent' => Money::BRL(0),
                'discountValue' => Money::BRL(0),
            ];
        }
    }

    /**
     * Apply Rule 4 - Um usuário que seja colaborador tem 20% de desconto no total do carrinho.
     *
     * @param string $userEmail The email of the user.
     *
     * @return mixed[] The result of the rule application, containing:
     *               - applied (bool): Whether the rule was applied.
     *               - discountPercent (float): The percentage discount applied.
     *               - discountValue (float): The discount value applied.
     */
    public function rule4(
        string $userEmail
    ): ?array {
        $url = 'http://'.Request::getHttpHost().'/api/v1/user/'.$userEmail;
        $response = Http::get($url);
        if ($response->successful()) {
            $responseData = $response->json();
            if (true === $responseData['data']['isEmployee']) {
                return [
                    'applied' => true,
                    'discountPercent' => Money::BRL(20),
                    'discountValue' => Money::BRL(0),
                ];
            } else {
                // se nao colaborador
                return [
                    'applied' => false,
                    'discountPercent' => Money::BRL(0),
                    'discountValue' => Money::BRL(0),
                ];
            }
        } else {
            // se vier qualquer coisa diferente de sucesso eu ignoro o email errado, inexistente ou nao colaborador
            return [
                'applied' => false,
                'discountPercent' => Money::BRL(0),
                'discountValue' => Money::BRL(0),
            ];
        }
    }

    /**
     * Apply Rule 5 - Caso seja a primeira compra, o usuário tem um desconto fixo de R$25,00 em compras
     *                acima de R$50,00. A rota /user/{email} retorna 404 caso o usuário não exista e se ele
     *                não existe ele é considerado um novo usuário.
     *
     * @param string $userEmail The email of the user.
     *
     * @return mixed[] The result of the rule application, containing:
     *               - applied (bool): Whether the rule was applied.
     *               - discountPercent (float): The percentage discount applied.
     *               - discountValue (float): The discount value applied.
     */
    public function rule5(
        string $userEmail
    ): ?array {
        $url = 'http://'.request()->getHttpHost().'/api/v1/user/'.$userEmail;
        $response = Http::get($url);
        if (409 == $response->status()) { // alterei pra usar 409 (conflito) quando nao existir, pois 404 pode indicar uma API foa do ar por ex.
            // claro que isso pode ser feito de outras formas com outras validacoes mais eficazes, mas pra este teste eu so mudei o status por seguranca
            return [
                'applied' => true,
                'discountPercent' => Money::BRL(0),
                'discountValue' => Money::BRL(2500), // NAO APLICAR direto, validar se carrinho terá mais de 50R$ no fianl
            ];
        } else {
            // se vier qualquer coisa diferente de 404 eu assumo que o email ja foi validado
            return [
                'applied' => false,
                'discountPercent' => Money::BRL(0),
                'discountValue' => Money::BRL(0),
            ];
        }
    }
}
