<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2020 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FacturaScripts\Core\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Dinamic\Lib\BusinessDocumentGenerator;
use FacturaScripts\Dinamic\Model;
use Symfony\Component\HttpFoundation\Response;

/**
 * Class DocumentStitcher
 *
 * @author Carlos García Gómez      <carlos@facturascripts.com>
 * @author Francesc Pineda Segarra  <francesc.pineda.segarra@gmail.com>
 */
class DocumentStitcher extends Controller
{

    /**
     * Array of document primary keys.
     *
     * @var array
     */
    public $codes = [];

    /**
     *
     * @var Model\Base\TransformerDocument[]
     */
    public $documents = [];

    /**
     * Model name source.
     *
     * @var string
     */
    public $modelName;

    /**
     * Fix escaped html from description.
     * 
     * @param string $description
     *
     * @return string
     */
    public function fixDescription($description)
    {
        return nl2br($this->toolBox()->utils()->fixHtml($description));
    }

    /**
     * Returns avaliable status to group this model.
     * 
     * @return array
     */
    public function getAvaliableStatus()
    {
        $status = [];

        $documentState = new Model\EstadoDocumento();
        $where = [new DataBaseWhere('tipodoc', $this->modelName)];
        foreach ($documentState->all($where) as $docState) {
            if (!empty($docState->generadoc)) {
                $status[] = $docState;
            }
        }

        return $status;
    }

    /**
     * Returns basic page attributes
     *
     * @return array
     */
    public function getPageData()
    {
        $data = parent::getPageData();
        $data['menu'] = 'sales';
        $data['showonmenu'] = false;
        $data['title'] = 'group-or-split';
        $data['icon'] = 'fas fa-magic';
        return $data;
    }

    /**
     * Runs the controller's private logic.
     *
     * @param Response              $response
     * @param Model\User            $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);
        $this->codes = $this->getCodes();
        $this->modelName = $this->getModelName();
        $this->loadDocuments();

        // duplicated request?
        $token = $this->request->request->get('multireqtoken', '');
        if (!empty($token) && $this->multiRequestProtection->tokenExist($token)) {
            $this->toolBox()->i18nLog()->warning('duplicated-request');
            return false;
        }

        $status = $this->request->request->get('status', '');
        if (!empty($status)) {
            $this->generateNewDocument((int) $status);
        }
    }

    /**
     * 
     * @param Model\Base\TransformerDocument $newDoc
     *
     * @return bool
     */
    protected function addDocument($newDoc)
    {
        foreach ($this->documents as $doc) {
            if ($doc->coddivisa != $newDoc->coddivisa || $doc->subjectColumnValue() != $newDoc->subjectColumnValue()) {
                $this->toolBox()->i18nLog()->warning('incompatible-document', ['%code%' => $newDoc->codigo]);
                return false;
            }
        }

        $this->documents[] = $newDoc;
        return true;
    }

    /**
     * 
     * @param BusinessDocumentGenerator $generator
     * @param int                       $idestado
     */
    protected function endGenerationAndRedit(&$generator, $idestado)
    {
        /// save new document status if no pending quantity
        foreach ($this->documents as $doc) {
            $update = true;
            foreach ($doc->getLines() as $line) {
                if ($line->servido < $line->cantidad) {
                    $update = false;
                    break;
                }
            }

            if ($update) {
                $doc->setDocumentGeneration(false);
                $doc->idestado = $idestado;
                $doc->save();
            }
        }

        /// redir to new document
        foreach ($generator->getLastDocs() as $doc) {
            $this->redirect($doc->url());
            $this->toolBox()->i18nLog()->notice('record-updated-correctly');
            break;
        }
    }

    /**
     * Generates a new document with this data.
     * 
     * @param int $idestado
     */
    protected function generateNewDocument($idestado)
    {
        /// group needed data
        $newLines = [];
        $properties = ['fecha' => $this->request->request->get('fecha', '')];
        $prototype = null;
        $quantities = [];
        foreach ($this->documents as $doc) {
            if (null === $prototype) {
                $prototype = $doc;
            }

            foreach ($doc->getLines() as $line) {
                $quantity = (float) $this->request->request->get('approve_quant_' . $line->primaryColumnValue(), '0');
                if (empty($quantity) && !empty($line->cantidad)) {
                    continue;
                }

                $quantities[$line->primaryColumnValue()] = $quantity;
                $newLines[] = $line;
            }
        }

        if (null === $prototype || empty($newLines)) {
            return;
        }

        /// generate new document
        $generator = new BusinessDocumentGenerator();
        $newClass = $this->getGenerateClass($idestado);
        if (!$generator->generate($prototype, $newClass, $newLines, $quantities, $properties)) {
            $this->toolBox()->i18nLog()->error('record-save-error');
            return;
        }

        $this->endGenerationAndRedit($generator, $idestado);
    }

    /**
     * Returns documents keys.
     * 
     * @return array
     */
    protected function getCodes()
    {
        $code = $this->request->request->get('code', []);
        if (!empty($code)) {
            return $code;
        }

        $codes = $this->request->get('codes', '');
        return explode(',', $codes);
    }

    /**
     * Returns the name of the new class to generate from this status.
     * 
     * @param int $idestado
     *
     * @return string
     */
    protected function getGenerateClass($idestado)
    {
        $estado = new Model\EstadoDocumento();
        $estado->loadFromCode($idestado);
        return $estado->generadoc;
    }

    /**
     * Returns model name.
     * 
     * @return string
     */
    protected function getModelName()
    {
        $model = $this->request->get('model', '');
        return $this->request->request->get('model', $model);
    }

    /**
     * Loads selected documents.
     */
    protected function loadDocuments()
    {
        $modelClass = '\\FacturaScripts\\Dinamic\\Model\\' . $this->modelName;
        foreach ($this->codes as $code) {
            $doc = new $modelClass();
            if ($doc->loadFromCode($code)) {
                $this->addDocument($doc);
            }
        }

        /// sort by date
        \uasort($this->documents, function ($doc1, $doc2) {
            if (\strtotime($doc1->fecha . ' ' . $doc1->hora) > \strtotime($doc2->fecha . ' ' . $doc2->hora)) {
                return 1;
            } elseif (\strtotime($doc1->fecha . ' ' . $doc1->hora) < \strtotime($doc2->fecha . ' ' . $doc2->hora)) {
                return -1;
            }

            return 0;
        });
    }
}
