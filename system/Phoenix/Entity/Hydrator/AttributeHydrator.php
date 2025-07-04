<?php
/**
 * @package     Phoenix/Entity
 * @subpackage  Hydrator
 * @file        AttributeHydrator.php
 * @author      Fernando Castillo <nando.castillo@outlook.com>
 * @date        2024-05-16 11:00:00
 * @version     1.0.0
 * @description Implementación del hidratador que utiliza Atributos de PHP para el mapeo.
 */
declare(strict_types=1);

namespace Phast\System\Phoenix\Entity\Hydrator;

use Phast\System\Phoenix\Core\Exceptions\HydrationException;
use Phast\System\Phoenix\Entity\Attributes\Column;
use Phast\System\Phoenix\Entity\EntityInterface;
use ReflectionClass;
use ReflectionException;
use TypeError;

/**
 * Hidratador que utiliza Atributos de PHP 8 para el mapeo objeto-relacional.
 *
 * Lee los metadatos de los atributos #[Column] en las propiedades de una entidad
 * para traducir dinámicamente entre arrays de datos y objetos de entidad.
 * Implementa un caché de metadatos estático para optimizar el rendimiento.
 */
class AttributeHydrator implements HydratorInterface {
   /**
    * Caché estático para los metadatos de las entidades.
    * Evita el coste de la reflexión en peticiones repetidas.
    * @var array<class-string, array<string, mixed>>
    */
   private static array $metadataCache = [];

   /**
    * {@inheritdoc}
    */
   public function hydrate(array $data, string $entityClass): EntityInterface {
      if (!class_exists($entityClass)) {
         throw new HydrationException("La clase de entidad '{$entityClass}' no existe.");
      }

      $metadata = $this->getMetadata($entityClass);

      try {
         /** @var EntityInterface $entity */
         $entity = (new ReflectionClass($entityClass))->newInstanceWithoutConstructor();
      } catch (ReflectionException $e) {
         throw new HydrationException("No se pudo instanciar la entidad '{$entityClass}'. ¿Tiene un constructor público sin parámetros obligatorios?", 0, $e);
      }

      foreach ($data as $columnName => $value) {
         if (isset($metadata['columnToPropertyMap'][$columnName])) {
            $propertyName = $metadata['columnToPropertyMap'][$columnName];
            try {
               // La asignación directa aprovechará la coerción de tipos de PHP 8+
               // y lanzará TypeError si los tipos son incompatibles.
               $entity->{$propertyName} = $value;
            } catch (TypeError $e) {
               throw new HydrationException("Error de tipo al hidratar '{$entityClass}::\${$propertyName}'. " . $e->getMessage(), 0, $e);
            }
         }
      }

      // Usamos el método de fábrica de la entidad para registrar que proviene de la base de datos.
      return $entityClass::newInstanceFromData($entity->getAttributes(), true);
   }

   /**
    * {@inheritdoc}
    */
   public function dehydrate(EntityInterface $entity): array {
      $entityClass = get_class($entity);
      $metadata = $this->getMetadata($entityClass);
      $data = [];

      foreach ($metadata['propertyToColumnMap'] as $propertyName => $columnName) {
         if (isset($entity->{$propertyName})) {
            $data[$columnName] = $entity->{$propertyName};
         }
      }

      return $data;
   }

   /**
    * Obtiene los metadatos de mapeo para una clase de entidad, usando un caché.
    *
    * @param class-string<EntityInterface> $entityClass
    * @return array<string, mixed>
    */
   private function getMetadata(string $entityClass): array {
      if (isset(self::$metadataCache[$entityClass])) {
         return self::$metadataCache[$entityClass];
      }

      $reflection = new ReflectionClass($entityClass);
      $propertyToColumnMap = [];

      foreach ($reflection->getProperties() as $property) {
         $attributes = $property->getAttributes(Column::class);
         if (empty($attributes)) {
            continue; // Ignorar propiedades sin el atributo #[Column]
         }

         $columnAttribute = $attributes[0]->newInstance();
         $propertyName = $property->getName();
         $columnName = $columnAttribute->name ?? $this->toSnakeCase($propertyName);

         $propertyToColumnMap[$propertyName] = $columnName;
      }

      return self::$metadataCache[$entityClass] = [
         'propertyToColumnMap' => $propertyToColumnMap,
         'columnToPropertyMap' => array_flip($propertyToColumnMap),
      ];
   }

   /**
    * Convierte una cadena de camelCase a snake_case.
    *
    * @param string $input La cadena en camelCase (ej. 'userName').
    * @return string La cadena en snake_case (ej. 'user_name').
    */
   private function toSnakeCase(string $input): string {
      return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $input));
   }
}