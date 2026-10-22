/**
 * Utilidades del frontend. Sin frameworks ni build.
 * El loop de emisión (api/procesar.php) se agrega en el paso del Procesador.
 */
(function () {
    'use strict';

    /** POST JSON a la app, mandando el token CSRF del formulario de la página. */
    async function postJson(url, datos) {
        const campo = document.querySelector('input[name="csrf"]');
        const respuesta = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': campo ? campo.value : ''
            },
            body: JSON.stringify(datos || {})
        });
        let cuerpo = null;
        try {
            cuerpo = await respuesta.json();
        } catch (e) {
            throw new Error('La app respondió algo inesperado. Recargá la página.');
        }
        if (!respuesta.ok || cuerpo.ok === false) {
            throw new Error(cuerpo && cuerpo.error ? cuerpo.error : 'No pudimos completar la operación.');
        }
        return cuerpo;
    }

    /**
     * Modo demostración: ningún botón hace nada, y en vez de la acción sale
     * un cartel explicando qué es esto. El bloqueo de verdad está en el
     * servidor; esto es para que se entienda.
     */
    function iniciarModoDemo() {
        const cuerpo = document.body;
        if (!cuerpo || cuerpo.dataset.demo !== '1') {
            return false;
        }

        const mensaje = cuerpo.dataset.demoMensaje || 'Esto es una demostración: las operaciones están deshabilitadas.';
        const contacto = cuerpo.dataset.demoContacto || 'https://ddw.com.ar';

        function mostrarCartel() {
            if (document.querySelector('.capa-demo')) {
                return;
            }
            const capa = document.createElement('div');
            capa.className = 'capa-demo';
            capa.innerHTML =
                '<div class="cartel-demo" role="dialog" aria-modal="true" aria-label="Demostración">'
                + '<h2>Demostración</h2>'
                + '<p></p>'
                + '<div class="botones">'
                + '<a class="boton" target="_blank" rel="noopener">Contactanos</a>'
                + '<button type="button" class="boton secundario" data-cerrar>Seguir mirando</button>'
                + '</div></div>';
            capa.querySelector('p').textContent = mensaje;
            capa.querySelector('a.boton').href = contacto;

            function cerrar() {
                capa.remove();
                document.removeEventListener('keydown', alTeclado);
            }
            function alTeclado(evento) {
                if (evento.key === 'Escape') {
                    cerrar();
                }
            }
            capa.addEventListener('click', function (evento) {
                if (evento.target === capa || evento.target.hasAttribute('data-cerrar')) {
                    cerrar();
                }
            });
            document.addEventListener('keydown', alTeclado);
            document.body.appendChild(capa);
            capa.querySelector('[data-cerrar]').focus();
        }

        // Los campos de archivo no deben poder abrir ni el selector.
        function trabarArchivos() {
            document.querySelectorAll('input[type="file"]').forEach(function (campo) {
                campo.disabled = true;
                campo.title = mensaje;
            });
        }
        trabarArchivos();

        // Cualquier cosa que haga algo: botones, envíos de formulario, campos de
        // archivo y los links que descargan o salen de la app.
        document.addEventListener('click', function (evento) {
            const archivo = evento.target.closest('input[type="file"], label');
            if (archivo && (archivo.matches('input[type="file"]') || archivo.querySelector('input[type="file"]'))) {
                evento.preventDefault();
                evento.stopPropagation();
                mostrarCartel();
                return;
            }

            const boton = evento.target.closest('button, input[type="submit"]');
            const link = evento.target.closest('a');

            if (boton && !boton.hasAttribute('data-cerrar')) {
                evento.preventDefault();
                evento.stopPropagation();
                mostrarCartel();
                return;
            }
            if (link && link.closest('.capa-demo') === null && link.closest('.cinta-demo') === null) {
                const url = link.getAttribute('href') || '';
                const esExterno = /^https?:/i.test(url) || link.target === '_blank';
                const esAccion = url.includes('descargar=') || url.includes('exportar.php') || url.includes('descargar.php');
                if (esAccion && !esExterno) {
                    evento.preventDefault();
                    mostrarCartel();
                }
            }
        }, true);

        document.addEventListener('submit', function (evento) {
            evento.preventDefault();
            evento.stopPropagation();
            mostrarCartel();
        }, true);

        // Al entrar se avisa una vez por visita; después, cada vez que tocan algo.
        let yaAviso = false;
        try {
            yaAviso = window.sessionStorage.getItem('demo-avisado') === '1';
            window.sessionStorage.setItem('demo-avisado', '1');
        } catch (e) {
            yaAviso = false;   // navegación privada o storage bloqueado
        }
        if (!yaAviso) {
            mostrarCartel();
        }

        return true;
    }

    /** Evita el doble clic en los formularios que tardan. */
    document.addEventListener('submit', function (evento) {
        const boton = evento.target.querySelector('button[type="submit"]');
        if (boton && !boton.disabled) {
            boton.dataset.textoOriginal = boton.textContent;
            boton.textContent = 'Un momento…';
            boton.disabled = true;
            // Si el navegador no navega (validación del lado del servidor), se reactiva.
            window.setTimeout(function () {
                boton.disabled = false;
                boton.textContent = boton.dataset.textoOriginal;
            }, 8000);
        }
    });

    /**
     * Loop de emisión: le pide a api/procesar.php UNA factura por llamada hasta que
     * el lote termina o se pausa. Si se cierra la pestaña, al volver aparece "Continuar".
     */
    function iniciarPanelDeProgreso() {
        const panel = document.getElementById('progreso');
        if (!panel || !panel.dataset.lote) {
            return;
        }

        const lote = Number(panel.dataset.lote);
        const texto = panel.querySelector('[data-texto]');
        const detalle = panel.querySelector('[data-detalle]');
        const relleno = panel.querySelector('.relleno');
        const botonParar = panel.querySelector('[data-parar]');
        let parar = false;
        let corriendo = false;

        const esperar = (ms) => new Promise((listo) => window.setTimeout(listo, ms));

        function pintar(r) {
            const hechas = (r.autorizadas || 0) + (r.rechazadas || 0);
            const total = r.total || 0;
            if (relleno && total > 0) {
                relleno.style.width = Math.round((hechas / total) * 100) + '%';
            }
            if (texto) {
                let linea = (r.autorizadas || 0) + ' de ' + total + ' emitidas';
                if (r.rechazadas) {
                    linea += ' · ' + r.rechazadas + ' con problemas';
                }
                if (r.pendientes) {
                    linea += ' · ' + r.pendientes + ' en la cola';
                }
                texto.textContent = linea;
            }
            if (detalle && r.ultima) {
                const partes = [];
                if (r.ultima.fila) {
                    partes.push('Fila ' + r.ultima.fila);
                }
                if (r.ultima.comprobante) {
                    partes.push('comprobante ' + r.ultima.comprobante);
                }
                if (r.ultima.mensaje) {
                    partes.push(r.ultima.mensaje);
                }
                detalle.textContent = partes.join(' · ');
            }
        }

        async function correr() {
            if (corriendo) {
                return;
            }
            corriendo = true;
            parar = false;
            if (botonParar) {
                botonParar.hidden = false;
            }

            try {
                while (!parar) {
                    const r = await postJson('api/procesar.php', { lote: lote });

                    if (r.ocupado) {
                        if (detalle) {
                            detalle.textContent = 'Hay otra pestaña emitiendo en este punto de venta: esperando…';
                        }
                        await esperar(2000);
                        continue;
                    }

                    pintar(r);

                    if (r.termino || r.pausado) {
                        window.location.href = 'lote.php?id=' + lote;
                        return;
                    }
                    await esperar(250);   // un respiro entre facturas
                }
                window.location.href = 'lote.php?id=' + lote;
            } catch (e) {
                if (detalle) {
                    detalle.textContent = e.message + ' Las facturas ya emitidas están guardadas: podés apretar Continuar.';
                }
                if (texto) {
                    texto.classList.add('con-error');
                }
            } finally {
                corriendo = false;
                if (botonParar) {
                    botonParar.hidden = true;
                }
            }
        }

        if (botonParar) {
            botonParar.addEventListener('click', function () {
                parar = true;
                botonParar.textContent = 'Terminando la factura en curso…';
            });
        }

        if (panel.dataset.arrancar === '1') {
            panel.hidden = false;
            correr();
        }
    }

    function iniciar() {
        // En demostración no arranca ningún loop: solo se miran las pantallas.
        if (iniciarModoDemo()) {
            return;
        }
        iniciarPanelDeProgreso();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }

    window.Facturador = { postJson: postJson };
})();
