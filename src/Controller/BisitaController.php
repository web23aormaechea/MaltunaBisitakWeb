<?php

namespace App\Controller;

use App\Entity\Bisita;
use App\Entity\Bisitaria;
use App\Entity\Egutegia;
use App\Form\BisitariaType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use App\Entity\Bilera;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;





final class BisitaController extends AbstractController
{
    private $em;

    /**
     * @param $em
     * @return
     */
    public function __construct(EntityManagerInterface $em){
        $this->em = $em;
    }

    #[Route('/', name: 'app_bisita', methods: ['GET', 'POST'])]
    public function index(Request $request, MailerInterface $mailer, EntityManagerInterface $entityManager): Response
    {
        // Obtener todas las fechas de Egutegia
        $dates = $entityManager->getRepository(Egutegia::class)
            ->createQueryBuilder('e')
            ->getQuery()
            ->getResult();

        // Convertir las fechas a formato Y-m-d
        $fechasSeleccionadas = [];
        foreach ($dates as $egutegia) {
            $fechasSeleccionadas[$egutegia->getData()->format('Y-m-d')] = 0; // Inicialmente sin visitas
        }

        // Obtener el conteo de visitas aprobadas por fecha
        $visitas = $entityManager->getRepository(Bisita::class)
            ->createQueryBuilder('b')
            ->select('b.Data, COUNT(b.id) as onartuta')
            ->where('b.Onartuta = 1')
            ->groupBy('b.Data')
            ->getQuery()
            ->getResult();

        // Asociar las visitas aprobadas a las fechas disponibles
        foreach ($visitas as $visita) {
            $fecha = $visita['Data']->format('Y-m-d'); // Convertimos DateTime a string
            if (isset($fechasSeleccionadas[$fecha])) {
                $fechasSeleccionadas[$fecha] = $visita['onartuta'];
            }
        }

        // Lógica para guardar la Bisita y los visitantes
        if ($request->isMethod('POST')) {
            // Recoger los datos del formulario
            $nondik = $request->get('nondik');  // Este campo es un string, puedes convertirlo a booleano si lo necesitas
            $taldea = $request->get('taldea') === 'on';  // Si 'on' está presente, será true, si no, será false
            // Taldea puede ser booleano (true o false)
            $kopurua = $request->get('kopurua');  // Este es el número de visitantes
            $data = new \DateTime($request->get('data'));  // Convertir el string de la fecha a DateTime
            $kopurua = !empty($kopurua) ? (int) $kopurua : null;

            // Crear la entidad Bisita
            $bisita = new Bisita();
            $bisita->setNondik($nondik);  // Puedes convertirlo a un valor booleano si lo deseas
            $bisita->setTaldea($taldea);
            $bisita->setKopurua($kopurua);  // Esto es un número, puede que quieras convertirlo en un valor booleano
            $bisita->setData($data);

            // Obtener los visitantes (los datos vienen como un string JSON)
            $bisitariakData = json_decode($request->get('bisitariakData'), true);

            foreach ($bisitariakData as $visitorData) {
                // Crear la entidad Bisitaria para cada visitante
                $bisitaria = new Bisitaria();
                $bisitaria->setIzena($visitorData['izena']);
                $bisitaria->setAbizena($visitorData['abizena']);
                $bisitaria->setEmail($visitorData['emaila']);

                // Asociar el 'nondik' y 'kopurua' a los visitantes
                // Asignamos los mismos valores que a la bisita
                $bisitaria->setNondik($nondik);  // Asignamos el mismo valor para todos


                // Relacionar al visitante con la bisita
                $bisitaria->setBisita($bisita);

                // Persistir al visitante
                $entityManager->persist($bisitaria);
            }

            // Persistir la visita
            $entityManager->persist($bisita);
            $entityManager->flush();  // Guardar todo

            // Obtener las direcciones de correo desde las variables de entorno
            $from = $_ENV['MAIL_FROM'];
            $to = $_ENV['MAIL_TO'];
            $WEB = $_ENV['WEB_URL'];
            $id = $bisita->getId();

            // Construir las URLs con la variable WEB_Pedestal
            $onartuUrl = $WEB . 'onartu/' . $id;
            $ukatuUrl = $WEB . 'ukatu/' . $id;

            // Crear y configurar el email
            $email = (new TemplatedEmail())
                ->from($from)
                ->to($to)
                ->subject('Bisita eskaera berria')
                ->htmlTemplate('mail/baseMail.html.twig' )
                ->context([
                    'from' => $nondik,
                    'group' => $taldea ? 'Bai' : 'Ez',
                    'quantity' => $kopurua ?? 'Ez zehaztuta',
                    'date' => $data->format('Y-m-d'),
                    'onartuUrl' => $onartuUrl,
                    'ukatuUrl' => $ukatuUrl,
                ]);
            // Enviar el email
            $mailer->send($email);


            // Redirigir o responder con éxito
            return $this->json(['redirect' => $this->generateUrl('app_success')]);
            // Puedes redirigir a una página de éxito si lo deseas
        }

        // Renderizar la vista con las fechas seleccionadas
        return $this->render('bisita/index.html.twig', [
            'calendarData' => $fechasSeleccionadas,
        ]);
    }


    #[Route('/success', name: 'app_success')]
    public function success(): Response
    {
        return $this->render('bisita/success.html.twig');
    }

    #[Route('/onartu/{id}', name: 'app_onartu')]
    public function Onartu(int $id, EntityManagerInterface $entityManager, MailerInterface $mailer
    ) {
        // Paso 1: Obtener la Bisita con la ID proporcionada
        $bisita = $entityManager->getRepository(Bisita::class)->find($id);

        // Verificar que la Bisita existe
        if (!$bisita) {
            throw $this->createNotFoundException('Bisita ez da aurkitu.');
        }

        // Paso 2: Cambiar el campo 'Onartuta' a false
        $bisita->setOnartuta(true);
        $entityManager->flush();  // Guardar los cambios en la base de datos

        // Paso 3: Obtener las Bisitaria (personas relacionadas con esta Bisita)
        $bisitariaList = $entityManager->getRepository(Bisitaria::class)->findBy(['Bisita' => $bisita]);
        $webUrl = $_ENV['WEB_PEdestal'];
        $qrUrl = $webUrl . 'bisita/pedestal/' . $bisita->getId();  // Si 'bisita' es un objeto
        // Obtener la URL de la variable de entorno
        $Url = $_ENV['WEB_URL'];
        $from = $_ENV['MAIL_FROM'];
        // Paso 4: Enviar el correo a cada Bisitaria
        foreach ($bisitariaList as $bisitaria) {
            $qrUrl = $webUrl . 'pedestal/bisita/imprimatu/' . $bisitaria->getId();
            // Generar el código QR
            $qrCode = new QrCode($qrUrl);
            $writer = new PngWriter();
            $qrDataUri = $writer->write($qrCode)->getDataUri(); // Esto lo pasa como Base64

            $email = (new TemplatedEmail())
                ->from($from)
                ->to($bisitaria->getEmail())  // Obtener el correo del Bisitaria
                ->subject('Bisita eskaera - Onartua')
                ->htmlTemplate('mail/onartuMail.html.twig' )
                ->context([
                    'izena' => $bisitaria->getIzena(),  // Nombre del Bisitaria
                    'abizena' => $bisitaria->getAbizena(),
                    'Url' => $Url,
                    'qrUrl' => $qrDataUri
                ]);

            $mailer->send($email);
        }
        return $this->render('bisita/MailSuccess.html.twig');
    }

    // Ruta para rechazar la solicitud de visita
    #[Route('/ukatu/{id}', name: 'app_ukatu', methods: ['GET'])]
    public function Ukatu(int $id, EntityManagerInterface $entityManager, MailerInterface $mailer
    ) {
        // Paso 1: Obtener la Bisita con la ID proporcionada
        $bisita = $entityManager->getRepository(Bisita::class)->find($id);

        // Verificar que la Bisita existe
        if (!$bisita) {
            throw $this->createNotFoundException('Bisita ez da aurkitu.');
        }

        // Paso 2: Cambiar el campo 'Onartuta' a false
        $bisita->setOnartuta(false);
        $entityManager->flush();  // Guardar los cambios en la base de datos

        // Paso 3: Obtener las Bisitaria (personas relacionadas con esta Bisita)
        $bisitariaList = $entityManager->getRepository(Bisitaria::class)->findBy(['Bisita' => $bisita]);

        // Obtener la URL de la variable de entorno
        $Url = $_ENV['WEB_URL'];
        $from = $_ENV['MAIL_FROM'];
        // Paso 4: Enviar el correo a cada Bisitaria
        foreach ($bisitariaList as $bisitaria) {
            $email = (new TemplatedEmail())
                ->from($from)
                ->to($bisitaria->getEmail())  // Obtener el correo del Bisitaria
                ->subject('Bisita eskaera - Ez Onartua')
                ->htmlTemplate('mail/ukatuMail.html.twig' )
                ->context([
                    'izena' => $bisitaria->getIzena(),  // Nombre del Bisitaria
                    'abizena' => $bisitaria->getAbizena(),
                    'Url' => $Url,
                ]);

            $mailer->send($email);
        }
        return $this->render('bisita/MailSuccess.html.twig');
    }

    #[Route('/bisita/pedestal/{id}', name: 'app_web_bisitaria_pedestal', methods: ['GET', 'POST'])]
    public function bisitariaForm(Request $request, Bisita $bisita): Response
    {
        $bisitaria = new Bisitaria();
        $bisitaria->setBisita($bisita); // Relacionar el bisitaria con la bilera actual

        // Crear y manejar el formulario
        $form = $this->createForm(BisitariaType::class, $bisitaria);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Guardar el bisitaria en la base de datos
            $this->em->persist($bisitaria);
            $this->em->flush();

            // Responder con un script que cierre el popup y recargue la ventana principal
            return $this->redirectToRoute('popup_success'); // Asegúrate de tener esta ruta definida correctamente
        }

        // Si no es válido o no se ha enviado, renderiza el formulario
        return $this->render('bisita/BisitariaPedestal.html.twig', [
            'form' => $form->createView(),
            'bisita' => $bisita,
        ]);
    }
    #[Route('/popup_success', name: 'popup_success')]
    public function popupSuccess(): Response
    {
        return $this->render('bisita/popup_success.html.twig');
    }



}
