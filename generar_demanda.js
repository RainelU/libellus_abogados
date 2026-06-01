// ============================================================
// Generador de demanda — completamente data-driven desde JSON
// Uso: node generar_demanda.js <ruta_json> [directorio_salida]
// Si no se pasan argumentos, lee ./demanda.json y escribe en ./
// ============================================================
const fs = require('fs');
const path = require('path');
const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    AlignmentType, BorderStyle, WidthType, ShadingType,
    PageNumber, Footer
} = require('docx');

// ---- Argumentos de línea de comandos ----
const args = process.argv.slice(2);
const jsonPath   = args[0] ? path.resolve(args[0]) : path.join(__dirname, 'demanda.json');
const outputDir  = args[1] ? path.resolve(args[1]) : __dirname;

// ---- Carga del JSON ----
const DATA = JSON.parse(fs.readFileSync(jsonPath, 'utf8'));
const FMT = DATA.formato;
const FONT = FMT.font;
const SIZE = FMT.size;
const SPACING = FMT.spacing;
const SPACING_TIGHT = FMT.spacing_tight;
const SINGLE = FMT.single;

// ============================================================
// HELPERS
// ============================================================

// Bordes de celda
const cellBorder = {
    top: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
    bottom: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
    left: { style: BorderStyle.SINGLE, size: 6, color: "000000" },
    right: { style: BorderStyle.SINGLE, size: 6, color: "000000" }
};

// Construye un TextRun a partir de un segmento (objeto del JSON) o un string
function buildRun(seg) {
    if (typeof seg === 'string') {
        return new TextRun({ text: seg, font: FONT, size: SIZE });
    }
    const text = seg.text !== undefined ? seg.text : (seg.label || '');
    const opts = {
        text,
        font: FONT,
        size: SIZE,
        bold: !!seg.bold,
        italics: !!seg.italics,
        underline: seg.underline ? {} : undefined,
        highlight: seg.highlight || undefined,
    };
    if (seg.break) opts.break = seg.break;
    return new TextRun(opts);
}

// Construye runs a partir de un arreglo de segmentos
function buildRuns(segments) {
    return segments.map(buildRun);
}

// Párrafo justificado normal
function p(segments, opts = {}) {
    return new Paragraph({
        alignment: opts.alignment || AlignmentType.JUSTIFIED,
        spacing: opts.spacing || SPACING,
        indent: opts.indent || undefined,
        children: buildRuns(segments)
    });
}

// Párrafo de continuación (sin separación inferior)
function pCont(segments, opts = {}) {
    return p(segments, { ...opts, spacing: SPACING_TIGHT });
}

// Párrafo centrado
function pc(segments, opts = {}) {
    return p(segments, { ...opts, alignment: AlignmentType.CENTER });
}

// Párrafo vacío
const empty = () => new Paragraph({ spacing: SINGLE, children: [new TextRun({ text: "", font: FONT, size: SIZE })] });

// Celda de tabla
function tcell(text, width) {
    return new TableCell({
        width: { size: width, type: WidthType.DXA },
        borders: cellBorder,
        margins: { top: 20, bottom: 20, left: 100, right: 100 },
        children: [new Paragraph({
            alignment: AlignmentType.LEFT,
            spacing: { line: 220, before: 0, after: 0 },
            children: [new TextRun({ text, font: FONT, size: SIZE, bold: true })]
        })]
    });
}

// ============================================================
// CONSTRUCTORES DE BLOQUES (iterando sobre el JSON)
// ============================================================

// PRESUMA: tabla con todas las filas del JSON
function buildPresuma() {
    const filas = DATA.presuma;
    return new Table({
        width: { size: 9360, type: WidthType.DXA },
        columnWidths: [3120, 6240],
        rows: filas.map(([k, v]) => new TableRow({
            children: [tcell(k, 3120), tcell(v, 6240)]
        }))
    });
}

// SUMA: itera sobre arreglo de segmentos
function buildSuma() {
    return p(DATA.suma);
}

// Título de sección
function buildSeccionTitulo(letra, titulo) {
    const text = letra ? `${letra}. ${titulo}` : titulo;
    return new Paragraph({
        alignment: AlignmentType.CENTER,
        spacing: { line: 276, before: 240, after: 180 },
        children: [new TextRun({ text, font: FONT, size: SIZE, bold: true })]
    });
}

// Acápite (cursiva + negrita + subrayado)
function buildAcapite(titulo) {
    return new Paragraph({
        alignment: AlignmentType.JUSTIFIED,
        spacing: { line: 276, before: 200, after: 120 },
        children: [new TextRun({ text: titulo, font: FONT, size: SIZE, bold: true, italics: true, underline: {} })]
    });
}

// Sub-acápite (cursiva + negrita, sin subrayado)
function buildSubAcapite(titulo) {
    return new Paragraph({
        alignment: AlignmentType.JUSTIFIED,
        spacing: { line: 276, before: 200, after: 100 },
        children: [new TextRun({ text: titulo, font: FONT, size: SIZE, bold: true, italics: true })]
    });
}

// Convierte índice 0-based a numeral romano
function toRoman(n) {
    const m = [
        ["M", 1000], ["CM", 900], ["D", 500], ["CD", 400], ["C", 100], ["XC", 90],
        ["L", 50], ["XL", 40], ["X", 10], ["IX", 9], ["V", 5], ["IV", 4], ["I", 1]
    ];
    let r = ""; let num = n;
    for (const [s, v] of m) { while (num >= v) { r += s; num -= v; } }
    return r;
}

// Párrafo romano: "I. Que, ..." con segmentos
function buildRomanoParrafo(romano, segments, opts = {}) {
    const runs = [
        new TextRun({ text: `${romano}. `, font: FONT, size: SIZE, bold: true }),
        new TextRun({ text: "Que, ", font: FONT, size: SIZE }),
        ...buildRuns(segments)
    ];
    return new Paragraph({
        alignment: AlignmentType.JUSTIFIED,
        spacing: opts.tight ? SPACING_TIGHT : SPACING,
        children: runs
    });
}

// Procesa una lista de romanos (cada item puede ser:
//   - array de segmentos (romano simple)
//   - objeto con {segmentos, tight, continuacion, encabezado_custom, lista}
// )
function buildRomanos(romanos) {
    const out = [];
    let romanoIdx = 0; // contador que sólo avanza con romanos NO continuación
    romanos.forEach(item => {
        if (Array.isArray(item)) {
            romanoIdx++;
            out.push(buildRomanoParrafo(toRoman(romanoIdx), item));
            return;
        }
        // Objeto
        if (item.continuacion) {
            // párrafo de continuación (sin numerar, sin "Que,")
            out.push(pCont(item.segmentos));
            return;
        }
        if (item.encabezado_custom) {
            romanoIdx++;
            // párrafo encabezado custom (ya trae su numeración o no)
            out.push(p(item.encabezado_custom));
            // lista (si la hay)
            if (item.lista) {
                item.lista.forEach(linea => {
                    out.push(new Paragraph({
                        alignment: AlignmentType.JUSTIFIED,
                        spacing: { line: 240, before: 0, after: 80 },
                        indent: { left: 360 },
                        children: [new TextRun({ text: linea, font: FONT, size: SIZE })]
                    }));
                });
            }
            return;
        }
        // Romano numerado con segmentos
        romanoIdx++;
        out.push(buildRomanoParrafo(toRoman(romanoIdx), item.segmentos, { tight: !!item.tight }));
    });
    return out;
}

// Acápite con romanos (deficiencias / improcedencia / conclusión)
function buildAcapiteCompleto(acap) {
    const out = [buildAcapite(acap.titulo)];

    // Apertura especial (sub-acápite "improcedencia")
    if (acap.apertura) {
        out.push(p(acap.apertura.encabezado_custom));
        acap.apertura.lista_inline.forEach((segs, i) => {
            const isLast = i === acap.apertura.lista_inline.length - 1;
            out.push(new Paragraph({
                alignment: AlignmentType.JUSTIFIED,
                spacing: { line: 240, before: i === 0 ? 80 : 0, after: isLast ? 120 : 80 },
                indent: { left: 360 },
                children: buildRuns(segs)
            }));
        });
        if (acap.apertura.cierre) {
            out.push(p(acap.apertura.cierre));
        }
    }

    if (acap.romanos) {
        out.push(...buildRomanos(acap.romanos));
    }

    if (acap.sub_acapites) {
        acap.sub_acapites.forEach(sub => {
            out.push(buildSubAcapite(sub.titulo));
            out.push(...buildRomanos(sub.romanos));
        });
    }
    return out;
}

// Procesa una sección completa
function buildSeccion(sec) {
    const out = [buildSeccionTitulo(sec.letra, sec.titulo)];
    if (sec.romanos) {
        out.push(...buildRomanos(sec.romanos));
    }
    if (sec.acapites) {
        sec.acapites.forEach(ac => out.push(...buildAcapiteCompleto(ac)));
    }
    if (sec.jurisprudencia) {
        out.push(new Paragraph({
            alignment: AlignmentType.JUSTIFIED,
            spacing: { line: 276, before: 200, after: 120 },
            children: [new TextRun({ text: "Jurisprudencia relevante.", font: FONT, size: SIZE, bold: true, underline: {} })]
        }));
        out.push(p(sec.jurisprudencia));
    }
    return out;
}

// Petitorio
function buildPetitorio() {
    const pet = DATA.petitorio;
    const out = [];
    out.push(buildSeccionTitulo(pet.titulo_seccion.letra, pet.titulo_seccion.titulo));
    out.push(p(pet.por_tanto));
    out.push(p(pet.ruego));
    pet.declaraciones.forEach(segs => {
        out.push(new Paragraph({
            alignment: AlignmentType.JUSTIFIED,
            spacing: SPACING,
            indent: { left: 360 },
            children: buildRuns(segs)
        }));
    });
    pet.peticiones_monetarias.forEach(segs => {
        out.push(new Paragraph({
            alignment: AlignmentType.JUSTIFIED,
            spacing: SPACING,
            indent: { left: 360 },
            children: buildRuns(segs)
        }));
    });
    out.push(p(pet.en_subsidio));
    out.push(empty());
    return out;
}

// Otrosíes
function buildOtrosies() {
    const out = [];
    DATA.otrosies.forEach((o, idx) => {
        const runs = [
            new TextRun({ text: o.label, font: FONT, size: SIZE, bold: true, underline: {} }),
            ...buildRuns(o.segmentos)
        ];
        out.push(new Paragraph({
            alignment: AlignmentType.JUSTIFIED,
            spacing: SPACING,
            children: runs
        }));
        if (idx < DATA.otrosies.length - 1) out.push(empty());
    });
    return out;
}

// ============================================================
// CONSTRUCCIÓN DEL DOCUMENTO
// ============================================================
const elementos = [];

// PRESUMA
elementos.push(buildPresuma());
elementos.push(empty());

// SUMA
elementos.push(buildSuma());
elementos.push(empty());

// TRIBUNAL
elementos.push(new Paragraph({
    alignment: AlignmentType.CENTER,
    spacing: SPACING,
    children: [new TextRun({ text: DATA.tribunal_titulo, font: FONT, size: SIZE, bold: true })]
}));
elementos.push(empty());

// COMPARECENCIA
elementos.push(p(DATA.comparecencia));

// INTRODUCCIÓN
elementos.push(p(DATA.introduccion));
elementos.push(empty());

// SECCIONES
DATA.secciones.forEach(sec => {
    elementos.push(...buildSeccion(sec));
});

// PETITORIO
elementos.push(...buildPetitorio());

// OTROSÍES
elementos.push(...buildOtrosies());

// ============================================================
// DOCUMENTO
// ============================================================
const doc = new Document({
    creator: DATA._meta.creator,
    title: DATA._meta.doc_title,
    styles: {
        default: {
            document: {
                run: { font: FONT, size: SIZE },
                paragraph: { spacing: SPACING }
            }
        }
    },
    sections: [{
        properties: {
            page: {
                size: FMT.page.size,
                margin: FMT.page.margin
            }
        },
        footers: {
            default: new Footer({
                children: [new Paragraph({
                    alignment: AlignmentType.RIGHT,
                    spacing: SINGLE,
                    children: [new TextRun({ children: [PageNumber.CURRENT], font: FONT, size: FMT.size_footer })]
                })]
            })
        },
        children: elementos
    }]
});

Packer.toBuffer(doc).then(buf => {
    // Asegurar que el directorio de salida existe
    if (!fs.existsSync(outputDir)) {
        fs.mkdirSync(outputDir, { recursive: true });
    }

    // Nombre del archivo: timestamp + nombre del _meta o output_filename
    const rawName = DATA._meta.output_filename || 'demanda.docx';
    const safeName = path.basename(rawName).replace(/[^a-zA-Z0-9_\-\.]/g, '_');
    const outFilename = Date.now() + '_' + safeName;
    const outPath = path.join(outputDir, outFilename);

    fs.writeFileSync(outPath, buf);
    console.log(`Demanda creada exitosamente: ${outFilename}`);
});
