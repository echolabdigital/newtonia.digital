<?php
/**
 * Diagnóstico: quando 0 resultados, descobre QUAL filtro está matando a busca.
 * Roda queries progressivamente removendo filtros até encontrar resultados.
 */

function cnpj_diagnose(array $f): array
{
    $diag = [];
    $base = $f;

    // Lista de filtros pra remover em ordem (do mais provável de matar pro menos)
    $candidatos = [];
    if (!empty($base['municipio']))   $candidatos[] = ['municipio',   'Cidade'];
    if (!empty($base['sub_vertical']))$candidatos[] = ['sub_vertical','Sub-vertical'];
    if (!empty($base['vertical']))    $candidatos[] = ['vertical',    'Vertical'];
    if (!empty($base['cnae']))        $candidatos[] = ['cnae',        'CNAE específico'];
    if (!empty($base['sem_mei']))     $candidatos[] = ['sem_mei',     'Sem MEI'];
    if (!empty($base['capital_min'])) $candidatos[] = ['capital_min', 'Capital mínimo'];
    if (!empty($base['idade_min']))   $candidatos[] = ['idade_min',   'Idade mínima'];
    if (!empty($base['porte']))       $candidatos[] = ['porte',       'Porte'];
    if (!empty($base['tem_email']))   $candidatos[] = ['tem_email',   'Com e-mail'];
    if (!empty($base['tem_tel']))     $candidatos[] = ['tem_tel',     'Com telefone'];
    if (!empty($base['uf']))          $candidatos[] = ['uf',          'UF'];

    // Smart default sempre aplicado
    $base['situacao'] = '02';

    try { cnpj_db()->exec("SET statement_timeout = '5s'"); } catch (\Throwable $e) {}

    foreach ($candidatos as [$key, $label]) {
        $teste = $base;
        unset($teste[$key]);
        [$where, $params] = cnpj_build_where($teste);
        try {
            $cnt = (int) cnpj_val(
                "SELECT COUNT(*) FROM (SELECT 1 FROM rf_estabelecimentos e $where LIMIT 101) sub",
                $params
            );
            $diag[] = [
                'remove'   => $label,
                'key'      => $key,
                'achou'    => $cnt,
                'culpado'  => $cnt > 0, // primeiro que retorna é o "culpado"
            ];
            if ($cnt > 0) break; // achou — para aqui
        } catch (\Throwable $e) {
            $diag[] = ['remove' => $label, 'key' => $key, 'achou' => -1, 'culpado' => false];
        }
    }
    try { cnpj_db()->exec("SET statement_timeout = 0"); } catch (\Throwable $e) {}

    return $diag;
}
