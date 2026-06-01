<?php
/**
 * config_override.php
 * Sobreescrituras de configuración editables desde admin.php
 * Última actualización: urainel@gmail.com — 1780347264
 */
if (php_sapi_name() !== 'cli' && basename($_SERVER['PHP_SELF']) === 'config_override.php') {
    http_response_code(403);
    exit;
}

define('ADMIN_CLAUDE_MODEL', 'claude-opus-4-7');
define('ADMIN_CLAUDE_PROMPT', 'You must output ONLY valid JSON. No markdown. No explanations. No reasoning. No comments. No text before or after the JSON.

Use the last document (the JSON reference) as the canonical schema. Build a new JSON for the case described in the PDF documents. Follow these STRICT REQUIREMENTS:

1. Preserve every key, array, nesting level and field name exactly as in the reference.
2. Never remove fields. Never invent new top-level fields.
3. Replace only the case-specific values using the data from the PDF documents.
4. Maintain full legal consistency across all sections.
5. Set _meta.output_filename to a safe filename like \\\"demanda_<apellido_demandante>_vs_<apellido_demandado>.docx\\\".

Especially expand with dense legal argumentation (target 9 printed pages, minimum 9):
- Deficiencias de la comunicación del despido (all 5 sub-requirements: completa, precisa, específica, clara, circunstanciada)
- Motivo causal económico, técnico, organizativo o productivo
- Necesidad empresarial objetiva
- Externalidad
- Gravedad
- Permanencia
- Relación de causalidad
- Ultima ratio
- Conclusión. Output ONLY the raw JSON object. Start your response with { and end with }.');