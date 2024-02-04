<?php
namespace App\Http\Controllers;

class ProductsController extends Controller
{
    public static function isPromotional(string $uid): bool // em prod usar classes e objetos adequados pra tratar UID
    {
        $products = config('api.promotional.products');
        // pode obter de um servico externo, tabela, cache, solr, etc
        // ex chamada API externa com token OAUTH
        // $response = Http::withHeaders([
        //     'Authorization' => 'Bearer ' . $token,
        // ])->get('http://localhost/api/products/...');

        return in_array($uid, $products);
    }

    public static function hasCategoryPromotional(string $uid): bool // em prod usar classes e objetos adequados pra tratar UID
    {
        $categories = config('api.promotional.categories'); // pode obter de um servico externo, tabela, cache, solr, etc

        return in_array($uid, $categories);
    }
}
