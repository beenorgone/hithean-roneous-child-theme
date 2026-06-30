#!/usr/bin/env node
'use strict';

const fs = require('fs');
const path = require('path');

const targetDir = process.argv[2] || __dirname;

function read(fileName) {
    return fs.readFileSync(path.join(targetDir, fileName), 'utf8');
}

function write(fileName, contents) {
    fs.writeFileSync(path.join(targetDir, fileName), contents);
}

function minifyCss(source) {
    return source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\s+/g, ' ')
        .replace(/\s*([{}:;,>])\s*/g, '$1')
        .replace(/;}/g, '}')
        .trim();
}

function minifyJs(source) {
    let output = '';
    let state = 'normal';
    let pendingSpace = false;
    let prevSignificant = '';
    let inRegexClass = false;

    function needsSpace(nextChar) {
        return /[A-Za-z0-9_$]/.test(prevSignificant) && /[A-Za-z0-9_$]/.test(nextChar);
    }

    function isRegexStart() {
        if (prevSignificant === '') {
            return true;
        }

        return '([{:=;,!&|?+-*%^~<>'.includes(prevSignificant);
    }

    function push(char) {
        if (pendingSpace && needsSpace(char)) {
            output += ' ';
        }

        output += char;

        if (!/\s/.test(char)) {
            prevSignificant = char;
        }

        pendingSpace = false;
    }

    for (let i = 0; i < source.length; i += 1) {
        const char = source[i];
        const next = source[i + 1] || '';

        if (state === 'line-comment') {
            if (char === '\n') {
                state = 'normal';
                pendingSpace = true;
            }
            continue;
        }

        if (state === 'block-comment') {
            if (char === '*' && next === '/') {
                state = 'normal';
                i += 1;
                pendingSpace = true;
            }
            continue;
        }

        if (state === 'single-quote' || state === 'double-quote' || state === 'template') {
            output += char;

            if (char === '\\') {
                output += next;
                i += 1;
                continue;
            }

            if (
                (state === 'single-quote' && char === '\'') ||
                (state === 'double-quote' && char === '"') ||
                (state === 'template' && char === '`')
            ) {
                state = 'normal';
                prevSignificant = char;
            }
            continue;
        }

        if (state === 'regex') {
            output += char;

            if (char === '\\') {
                output += next;
                i += 1;
                continue;
            }

            if (char === '[') {
                inRegexClass = true;
                continue;
            }

            if (char === ']' && inRegexClass) {
                inRegexClass = false;
                continue;
            }

            if (char === '/' && !inRegexClass) {
                state = 'regex-flags';
                prevSignificant = '/';
            }
            continue;
        }

        if (state === 'regex-flags') {
            if (/[a-z]/i.test(char)) {
                output += char;
                prevSignificant = char;
                continue;
            }

            state = 'normal';
            i -= 1;
            continue;
        }

        if (char === '/' && next === '/') {
            state = 'line-comment';
            i += 1;
            continue;
        }

        if (char === '/' && next === '*') {
            state = 'block-comment';
            i += 1;
            continue;
        }

        if (char === '\'') {
            state = 'single-quote';
            push(char);
            continue;
        }

        if (char === '"') {
            state = 'double-quote';
            push(char);
            continue;
        }

        if (char === '`') {
            state = 'template';
            push(char);
            continue;
        }

        if (char === '/' && isRegexStart()) {
            state = 'regex';
            inRegexClass = false;
            push(char);
            continue;
        }

        if (/\s/.test(char)) {
            pendingSpace = true;
            continue;
        }

        push(char);
    }

    return output.trim();
}

write('lucky-wheel.min.css', minifyCss(read('lucky-wheel.css')) + '\n');
write('lucky-wheel.min.js', minifyJs(read('lucky-wheel.js')) + '\n');
