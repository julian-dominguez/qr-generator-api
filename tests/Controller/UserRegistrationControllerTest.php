<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\UserRegistrationController;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Doctrine\DBAL\Driver\PDO\Exception as DriverPDOException;

class UserRegistrationControllerTest extends TestCase
{
    private ManagerRegistry $managerRegistryMock;
    private EntityManagerInterface $entityManagerMock;
    private ValidatorInterface $validatorMock;
    private UserPasswordHasherInterface $passwordHasherMock;
    private ContainerInterface $containerMock;

    protected function setUp(): void
    {
        // Crear mocks para los controladores dependientes
        $this->managerRegistryMock = $this->createMock(ManagerRegistry::class);
        $this->entityManagerMock = $this->createMock(EntityManagerInterface::class);
        $this->validatorMock = $this->createMock(ValidatorInterface::class);
        $this->passwordHasherMock = $this->createMock(UserPasswordHasherInterface::class);
        $this->containerMock = $this->createMock(ContainerInterface::class);
    }


    /**
     * Prueba el método index con datos que faltan.
     *
     * Este caso de prueba verifica que el método index del UserRegistrationController
     * devuelve una respuesta JSON con un código de estado 400 y un mensaje que indica que
     * el correo electrónico y la contraseña son obligatorios.
     *
     * @return void
     */
    public function testIndexWithMissingData(): void
    {
        $request = new Request([], [], [], [], [], [], '[]');
        $controller = new userRegistrationController(
            $this->validatorMock, $this->passwordHasherMock
        );
        $controller->setContainer($this->containerMock);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('email and password are required');
        $this->expectExceptionCode(JsonResponse::HTTP_BAD_REQUEST);

        $controller->index($this->managerRegistryMock, $request);
    }


    /**
     * Prueba el método index con un correo electrónico no válido.
     *
     * Este caso de prueba verifica que el método index del UserRegistrationController
     * devuelve una respuesta JSON con un código de estado 400 y un mensaje que indica que
     * la dirección de correo electrónico no es válida.
     *
     * @return void
     */
    public function testIndexWithInvalidEmail(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode(['email' => 'invalid', 'password' => 'valid']));
        $controller = new userRegistrationController(
            $this->validatorMock, $this->passwordHasherMock
        );
        $controller->setContainer($this->containerMock);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('invalid email address');
        $this->expectExceptionCode(JsonResponse::HTTP_BAD_REQUEST);

        $controller->index($this->managerRegistryMock, $request);

    }

    /**
     * Prueba el método index con una contraseña corta.
     *
     * Este caso de prueba verifica que el método index del UserRegistrationController
     * devuelve una respuesta JSON con un código de estado 400 y un mensaje que indica que
     * la contraseña debe tener al menos 6 caracteres.
     *
     * @return void
     */
    public function testIndexWithShortPassword(): void
    {
        $request = new Request([], [], [], [], [], [],
            json_encode(['email' => 'valid@example.com', 'password' => 'short'])
        );

        $controller = new userRegistrationController(
            $this->validatorMock, $this->passwordHasherMock
        );
        $controller->setContainer($this->containerMock);

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('password must be at least 6 characters long');
        $this->expectExceptionCode(JsonResponse::HTTP_BAD_REQUEST);

        $controller->index($this->managerRegistryMock, $request);
    }
//
    /**
     * Prueba el método index con datos válidos.
     *
     * @return void
     */
    public function testIndexWithValidData(): void
    {
        $request = new Request([], [], [], [], [], [],
            json_encode(['email' => 'valid@example.com', 'password' => 'validpassword'])
        );

        $this->managerRegistryMock->expects($this->once())->method('getManager')->willReturn(
            $this->createMock(EntityManagerInterface::class)
        );
        $violations = $this->createMock(ConstraintViolationListInterface::class);
        $violations->expects($this->once())->method('count')->willReturn(0);
        $this->validatorMock->expects($this->once())->method('validate')->willReturn($violations);

        $controller = new userRegistrationController(
            $this->validatorMock, $this->passwordHasherMock
        );
        $controller->setContainer($this->containerMock);
        $response = $controller->index($this->managerRegistryMock, $request);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertEquals(['message' => "the user valid@example.com as created successfully"],
            json_decode($response->getContent(), true));
    }

    /**
     * Prueba el método index del UserRegistrationController con una UniqueConstraintViolationException.
     *
     * Esta prueba verifica que cuando se llama al método index del UserRegistrationController con una petición
     * que contiene un correo electrónico que ya está registrado, devuelve un JsonResponse con un código
     * de estado 409 (Conflicto) y un mensaje que indica que el correo electrónico ya está registrado.
     *
     * @return void
     */
    public function testIndexWithUniqueConstraintViolationException(): void
    {
        $request = new Request([], [], [], [], [], [], json_encode([
            'email' => 'test@example.com',
            'password' => 'password'
        ]));

        $violationList = new ConstraintViolationList();
        $driverException = new DriverPDOException("Unique constraint violation");

        $this->managerRegistryMock->method('getManager')->willReturn($this->entityManagerMock);
        $this->validatorMock->method('validate')->willReturn($violationList);

        $uniqueConstraintViolationException = new UniqueConstraintViolationException(
            $driverException,
            $driverException->getSQLState());

        $this->entityManagerMock->expects($this->once())
            ->method('persist')
            ->willThrowException($uniqueConstraintViolationException);


        $controller = new UserRegistrationController(
            $this->validatorMock,
            $this->passwordHasherMock,
        );

        $controller->setContainer($this->createMock(ContainerInterface::class));

        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('This email is already registered. Please use a different email address.');
        $this->expectExceptionCode(JsonResponse::HTTP_CONFLICT);

        $controller->index($this->managerRegistryMock, $request);
    }

}
