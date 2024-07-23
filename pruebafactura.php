<?php

function facturape_config() {
    return [
        'name' => 'FacturaPE',
        'description' => 'Módulo para la visualización de facturas para clientes.',
        'author' => 'HWPeru',
        'version' => '1.0',
        'fields' => []
    ];
}
function facturape_activate() {
    // Crear la tabla en la base de datos si es necesario
    return ['status' => 'success', 'description' => 'Módulo activado correctamente'];
}

function facturape_deactivate() {
    // Limpiar configuraciones si es necesario
    return ['status' => 'success', 'description' => 'Módulo desactivado correctamente'];
}
use WHMCS\ClientArea;
use WHMCS\Database\Capsule;

// Muestra y ejecuta la funcion del boton "generar factura"
function facturape_output($vars) {
    $modulelink = $vars['modulelink'];
    $action = isset($_GET['action']) ? $_GET['action'] : 'default';

    switch ($action) {
        case 'generate_invoice':
            try {
                $invoiceId = $_REQUEST['invoiceId'];
                error_log("Generating invoice for ID: " . $invoiceId);
                $response = enviarFacturaAPeru($invoiceId);
                echo '<script type="text/javascript">';
                echo 'alert("Factura emitida correctamente.");';
                echo 'window.location.href = "invoices.php?action=edit&id=' . $invoiceId . '";';
                echo '</script>';
            } catch (Exception $e) {
                error_log("Error generating invoice: " . $e->getMessage());
                echo '<script type="text/javascript">';
                echo 'alert("' . $e->getMessage() . '");';
                echo 'window.history.back();';
                echo '</script>';
            }
            break;

        case 'delete_invoice':
            try {
                $id = $_POST['id'];
                // Eliminamos la factura de la base de datos
                $result = Capsule::table('my_custom_table')->where('id', $id)->delete();
                
                if ($result) {
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'No se pudo eliminar la factura.']);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
            }
            die(); // Importante para no ejecutar código WHMCS adicional no deseado

        default:
            echo '<p>Este es el contenido de mi módulo FacturaPE en el área de administración.</p>';
            break;
    }

    die(); // Importante para no ejecutar código WHMCS adicional no deseado
}



require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/clientareafunctions.php';
require_once __DIR__ . '/../../../vendor/autoload.php';

//Genera la factura y conecta con el API facturador
function enviarFacturaAPeru($invoiceId) {
    
    $numeroFacturaExistente = obtenerNumeroFacturaExistente($invoiceId);
    if ($numeroFacturaExistente !== null) {
        throw new Exception('Ya hay una factura generada con el número: ' . $numeroFacturaExistente);
    }
    
    // Obteniendo la factura
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    $client = Capsule::table('tblclients')->where('id', $invoice->userid)->first();
    
    // Obteniendo el campo RUC personalizado
    $customFieldRUC = Capsule::table('tblcustomfieldsvalues')
        ->where('relid', $client->id)
        ->where('fieldid', '2') // Asegúrate de que este es el ID correcto para el campo del RUC
        ->value('value');
    /*----------CÓDIGO NUEVO----------*/    
    $isRUC = strlen($customField) == 11; // Asumiendo que los RUC tienen 11 dígitos
    $documentType = $isRUC ? "01" : "03"; // 01 para Factura, 03 para Boleta
    
    if ($isRUC && empty($client->companyname)) {
        throw new Exception('Para emitir una factura con RUC, el campo "company" debe estar lleno.');
    }

    if (!$isRUC && (empty($client->firstname) || empty($client->lastname))) {
        throw new Exception('Para emitir una boleta con DNI, los campos "nombre" y "apellido" deben estar llenos.');
    }
    /*-------------CIERRE DE CÓDIGO------------*/
        
    $districtId = $client->address2;
    $order = Capsule::table('tblorders')->where('invoiceid', $invoiceId)->first();
    $numeroOrdenDeCompra = $order ? $order->ordernum : '999';
    $couponCode = $order->promocode; // Suponiendo que se puede obtener el código del cupón de esta manera
    $discountAmount = 0; // Inicializar el descuento en 0

    // Calcular descuento basado en cupón
    if (!empty($couponCode)) {
        $coupon = Capsule::table('tblpromotions')->where('code', $couponCode)->first();
        if ($coupon) {
            $discountAmount = $coupon->value;
            // Ajustar el descuento si es un porcentaje
            if ($coupon->type == 'Percentage') {
                $discountAmount = ($discountAmount / 100) * $invoice->subtotal;
            }
        }
    }

    // Obtener los ítems de la factura
    $invoiceItems = Capsule::table('tblinvoiceitems')->where('invoiceid', $invoiceId)->get();

    // Calcular descuento basado en ítems manuales
    foreach ($invoiceItems as $item) {
        if (stripos($item->description, 'descuento') !== false && $item->amount < 0) {
            $discountAmount += abs($item->amount);
        }
    }

    // Obtener las transacciones asociadas a la factura
    $transactions = Capsule::table('tblaccounts')->where('invoiceid', $invoiceId)->get();
    // Procesar los pagos
    $pagos = [];
    foreach ($transactions as $transaction) {
        $paymentMethod = $transaction->gateway; // El método de pago utilizado
        $reference = $transaction->transid; // El Transaction ID
        $amount = $transaction->amountin; // Monto total pagado en la transacción
        // Mapeo de métodos de pago a códigos cortos
        $paymentMethodMap = [
            'mailin' => '04',
            'micuentawebpe' => '02',
            // Otros métodos de pago
        ];
        // Mapeando al código correcto, con un valor predeterminado de '01' si no se encuentra el método
        $codigoMetodoPago = $paymentMethodMap[$paymentMethod] ?? '04';
        // Añadir el método de pago a la lista de pagos
        $pagos[] = [
            "codigo_metodo_pago" => $codigoMetodoPago,
            "referencia" => $reference,
            "codigo_destino_pago" => "cash",
            "monto" => round($amount, 2) // Monto total pagado en esta transacción
        ];
    }
    $totalDue = $invoice->total;
    $totalDueBase = $invoice->total / 1.18;
    $detraccionPorcentaje = 0.14; // Porcentaje de detracción (14%)

    // Calcular el monto de la detracción
    $montoDetraccion = $totalDue * $detraccionPorcentaje;

    // Resto del código para generar los items y totales

    // Excluir elementos promocionales del cálculo del subtotal
    $subtotal = $invoiceItems->reject(function ($item) {
        return stripos($item->description, 'Descuento') !== false;
    })->sum('amount');
    
    // Calcular el descuento global
    $descuentoGlobal = $discountAmount / 1.18;
    
    $baseDescuento = $invoiceItems->reject(function ($item) {
        return stripos($item->description, 'Descuento') !== false;
    })->sum(function ($item) {
        return $item->amount / 1.18; // Restar el IGV para obtener la base imponible
    });
    
    // Calcular la diferencia entre $baseDescuento y descuentoGlobal
    $totalOperacionesGravadas = $baseDescuento - $descuentoGlobal;
    
    $porcentajeDescuento = $descuentoGlobal / $baseDescuento;

    $itemsJson = $invoiceItems->reject(function ($item) {
        return stripos($item->description, 'Descuento') !== false;
    })->map(function ($item) {
        $cantidad = isset($item->quantity) && is_numeric($item->quantity) ? $item->quantity : 1;
        $precioSinIgv = $item->taxed ? $item->amount / 1.18 : $item->amount;
        $igvItem = $item->amount - $precioSinIgv;
        return [
            "codigo_interno" => $item->relid ?: '9999',
            "descripcion" => $item->description,
            "unidad_de_medida" => "NIU",
            "cantidad" => $cantidad,
            "valor_unitario" => round($precioSinIgv, 2),
            "codigo_tipo_precio" => "01",
            "precio_unitario" => $item->amount,
            "codigo_tipo_afectacion_igv" => "10",
            "total_base_igv" => round($precioSinIgv * $cantidad, 2),
            "porcentaje_igv" => 18,
            "total_igv" => round($igvItem * $cantidad, 2),
            "total_impuestos" => round($igvItem * $cantidad, 2),
            "total_valor_item" => round($precioSinIgv * $cantidad, 2),
            "total_item" => round($item->amount * $cantidad, 2),
        ];
    })->toArray();

    // Preparando el JSON de la factura
    $invoiceData = [
        "serie_documento" => $isRUC ? "F003" : "B001",
        "numero_documento" => "#",
        "fecha_de_emision" => date('Y-m-d'),
        "hora_de_emision" => date('H:i:s'),
        "codigo_tipo_operacion" => "0101",
        "codigo_tipo_documento" => $documentType,
        "codigo_tipo_moneda" => $invoice->currency ?: 'PEN',
        "fecha_de_vencimiento" => date('Y-m-d'),
        "numero_orden_de_compra" => $numeroOrdenDeCompra,
        "datos_del_cliente_o_receptor" => [
            "codigo_tipo_documento_identidad" => $isRUC ? "6" : "1",
            "numero_documento" => $customField,
            "apellidos_y_nombres_o_razon_social" => $isRUC ? $client->companyname : $client->firstname . ' ' . $client->lastname,
            "codigo_pais" => "PE",
            "ubigeo" => $districtId,
            "direccion" => $client->address1,
            "correo_electronico" => $client->email,
            "telefono" => $client->phonenumber,
            "district_id" => $districtId,
        ],
        "descuentos" => [
            [
                "codigo" => "02",
                "descripcion" => "Descuentos globales que afectan la base imponible del IGV/IVAP",
                "factor" => round($porcentajeDescuento, 5),
                "monto" => round($descuentoGlobal, 2),
                "base" => round($baseDescuento, 2),
            ]
        ],
        "totales" => [
            "total_descuentos" => $discountAmount,
            "total_operaciones_gravadas" => round($totalOperacionesGravadas, 2),
            "total_igv" => $invoice->tax ?: 0.00,
            "total_impuestos" => $invoice->tax ?: 0.00,
            "total_valor" => round($totalOperacionesGravadas, 2),
            "total_venta" => $totalDue,
        ],
        "leyendas" => [
            [
                "codigo" => "2006",
                "valor" => "Operación sujeta a detracción"
            ]
        ],
        "detraccion" => [
            "codigo_tipo_detraccion" => "022",
            "porcentaje" => 12.00,
            "monto" => round($montoDetraccion, 2),
            "codigo_metodo_pago" => "001",
            "cuenta_bancaria" => "00-051-154274"
        ],
        "pagos" => $pagos,
        "items" => $itemsJson,
    ];

    if ($descuentoGlobal > 0) {
        $invoiceData['descuentos'] = [
            [
                "codigo" => "02",
                "descripcion" => "Descuentos globales que afectan la base imponible del IGV/IVAP",
                "factor" => round($porcentajeDescuento, 5),
                "monto" => round($descuentoGlobal, 2),
                "base" => round($baseDescuento, 2),
            ]
        ];
    } else {
        unset($invoiceData['descuentos']);
    }

    if ($invoiceData['totales']['total_venta'] >= 700) {
        $invoiceData['codigo_tipo_operacion'] = "1001";

        $invoiceData['detraccion'] = [
            "codigo_tipo_detraccion" => "022",
            "porcentaje" => 12.00,
            "monto" => round($montoDetraccion, 2),
            "codigo_metodo_pago" => "001",
            "cuenta_bancaria" => "00-051-154274"
        ];

        $invoiceData['leyendas'] = [
            [
                "codigo" => "2006",
                "valor" => "Operación sujeta a detracción"
            ]
        ];
    } else {
        unset($invoiceData['detraccion']);
        unset($invoiceData['leyendas']);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://conta.factura.hwperu.com/api/documents");
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($invoiceData));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer rkEUpVVEvdiI8FkwalXCjjMgMUZfGKctLg4TA922AoHkRqwHCv'
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        throw new Exception(curl_error($ch));
    }
    curl_close($ch);

    error_log("Invoice Data: " . print_r($invoiceData, true));
    error_log('Respuesta de la API: ' . $response);

    $responseData = json_decode($response, true);

    error_log('Datos de respuesta decodificados: ' . print_r($responseData, true));

    if (isset($responseData['error'])) {
        throw new Exception('Error al enviar la factura: ' . $responseData['message']);
    }

    guardarDatosFactura($invoiceId, $responseData, $invoiceData);

    return $responseData;
}


// Obtener el ID de usuario asociado al invoice para relacionar la lista de facturas emitidas en ?m=facturape
function getUserIdFromInvoiceId($invoiceId) {
    
    $invoice = Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    if ($invoice) {
        return $invoice->userid;
    }
    return null; // Retorna null si no se puede encontrar el ID de usuario
}

//Guarda los batos en la Tabla de DB my_custom_table
function guardarDatosFactura($invoiceId, $data, $invoiceData) {
    
    $userId = getUserIdFromInvoiceId($invoiceId);
    
    if ($userId) {
    // Modificar para usar la información de la factura generada
    $xmlLink = $data['links']['xml'] ?? 'N/A';
    $pdfLink = $data['links']['pdf'] ?? 'N/A';
    $cdrLink = $data['links']['cdr'] ?? 'N/A';
    $number = $data['data']['number'] ?? 'N/A';
    $stateTypeDescription = $data['data']['state_type_description'] ?? 'N/A';

    // Obtener la fecha de emisión de la factura de $invoiceData
    $creationDate = date('Y-m-d', strtotime($invoiceData['fecha_de_emision'])) ?? 'N/A';
    
    // Obtener el nombre de la empresa/razón social de $invoiceData
    $companyName = $invoiceData['datos_del_cliente_o_receptor']['apellidos_y_nombres_o_razon_social'] ?? 'N/A';
    
    // Obtener el monto total de $invoiceData
    $totalAmount = $invoiceData['totales']['total_venta'] ?? 'N/A';

    // Insertar los datos en la tabla personalizada
    Capsule::table('my_custom_table')->insert([
        'invoice_id' => $invoiceId,
        'xml_link' => $xmlLink,
        'pdf_link' => $pdfLink,
        'cdr_link' => $cdrLink,
        'number' => $number,
        'state_type_description' => $stateTypeDescription,
        'creation_date' => $creationDate,
        'company_name' => $companyName,
        'total_amount' => $totalAmount,
        'userid' => $userId, // Agregar el ID de usuario
    ]);
}
else {
        // No se pudo obtener el ID de usuario asociado al invoice
        // Realizar alguna acción de manejo de errores, como registrar un mensaje de error, etc.
    }
}

//Esta funcion detecta el último numero  del api facturado y genera la siguiente
function generarNumeroDocumento($ultimoNumeroDocumento) {
    // Incrementar el último número y devolver el nuevo número como string con el formato deseado
    return sprintf('%07d', $ultimoNumeroDocumento + 1);
}

// Función para obtener el último número de documento de la API o de la base de datos
function obtenerUltimoNumeroDocumentoDeApiODeBaseDeDatos() {
    // Lógica para obtener el último número de documento...
    // Esta función debe ser implementada según tu lógica de negocio y cómo almacenas esta información.
    return 123; // Ejemplo, debes obtener este valor de forma dinámica
}

// Muestra la lista de facturas generadas en el área de cliente segun su ID
function facturape_clientarea($vars) {
    $ca = new WHMCS\ClientArea();

    $ca->setPageTitle('FacturaPE');
    $ca->addToBreadCrumb('index.php', 'Home');
    $ca->addToBreadCrumb('facturape', 'FacturaPE');

    // Verificar si el usuario está logueado
    if (!$ca->isLoggedIn()) {
        // Si no está logueado, redirigirlo al login
        $ca->redirect('login.php');
        return '';
    }

    // Obtener información del usuario autenticado
    $currentUser = new WHMCS\Authentication\CurrentUser();
    $authUser = $currentUser->user();

    if ($authUser) {
        $ca->assign('userFullname', $authUser->fullName);
        $selectedClient = $currentUser->client();
        if ($selectedClient) {
            // Obtener el ID del cliente
            $clientId = $selectedClient->id;

            // Obtener las facturas asociadas al cliente
            $clientInvoices = retrieve_client_invoices($clientId);

            // Asignar las facturas al template
            $ca->assign('invoices', $clientInvoices);

            // Asignar el número de facturas al template
            $ca->assign('clientInvoiceCount', count($clientInvoices));
        }
    } else {
        $ca->assign('userFullname', 'Guest');
    }

    // Definir el archivo de template a usar
    $ca->setTemplate('facturape_view');

    return [
        'pagetitle' => 'Mis Facturas',
        'breadcrumb' => ['index.php?m=facturape' => 'FacturaPE'],
        'templatefile' => 'facturape_view',
        'requirelogin' => true, // Requiere que el usuario esté autenticado para acceder
        'forcessl' => false, // No fuerza el uso de SSL
        'vars' => [
            'testvar' => 'demo',
            'anothervar' => 'value',
            'sample' => 'test',
        ],
    ];
}

// Función para recuperar las facturas del cliente
function retrieve_client_invoices($clientId) {
    // Utiliza la API de WHMCS para obtener las facturas del cliente
    $clientInvoices = WHMCS\Database\Capsule::table('my_custom_table')
        ->where('userid', $clientId) // Aquí es donde estás utilizando la columna incorrecta
        ->get();
    
    // Filtrar las facturas asociadas al cliente
    $invoices = [];
    foreach ($clientInvoices as $invoice) {
        // Verificar si el invoice_id está asignado al cliente
        if (invoice_belongs_to_client($invoice->invoice_id, $clientId)) {
            $invoices[] = $invoice;
        }
    }
    
    return $invoices;
}

// Función para verificar si una factura pertenece a un cliente
function invoice_belongs_to_client($invoiceId, $clientId) {
    // Lógica para verificar si una factura pertenece a un cliente...
    // Esta función debe ser implementada según tu lógica de negocio.
    // Por ejemplo, puedes verificar si el invoice_id está asociado al cliente en tu sistema.
    return true; // Devuelve true si la factura pertenece al cliente, de lo contrario false.
}

// Validador de factura para no generar doble.
function obtenerNumeroFacturaExistente($invoiceId) {
    // Obtener el número de factura generado para el invoice dado en la tabla personalizada
    $numeroFactura = Capsule::table('my_custom_table')
        ->where('invoice_id', $invoiceId)
        ->value('number');

    return $numeroFactura;
}
 