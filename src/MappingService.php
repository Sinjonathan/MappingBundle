<?php

namespace Ehyiah\MappingBundle;

use Doctrine\ORM\EntityManagerInterface;
use Ehyiah\MappingBundle\Attributes\MappingAware;
use Ehyiah\MappingBundle\DependencyInjection\TransformerLocator;
use Ehyiah\MappingBundle\Exceptions\MappingException;
use Ehyiah\MappingBundle\Exceptions\NotMappableObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\PropertyAccess\PropertyAccessor;

final class MappingService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private TransformerLocator $transformationLocator,
        private LoggerInterface $mappingLogger,
    ) {
    }

    /**
     * @throws ReflectionException
     * @throws MappingException
     */
    public function mapToTarget(object $mappingAwareSourceObject, ?object $targetObject = null, bool $persist = false, bool $flush = false): object
    {
        $mapping = $this->getPropertiesToMap($mappingAwareSourceObject);

        if (null === $targetObject) {
            $targetObject = new $mapping['targetClass']();

            if (true === $persist) {
                $this->entityManager->persist($targetObject);
            }
        }

        $propertyAccessor = new PropertyAccessor();
        $modificationCount = 0;

        foreach ($mapping['properties'] as $sourcePropertyName => $targetMappingOptions) {
            $targetPropertyPath = $targetMappingOptions['target'];

            if ($propertyAccessor->isWritable($targetObject, $targetPropertyPath)) {
                if ($propertyAccessor->isReadable($mappingAwareSourceObject, $sourcePropertyName)) {
                    if (isset($targetMappingOptions['transformer'])) {
                        $transformer = $this->transformationLocator->returnTransformer($targetMappingOptions['transformer']);
                        $value = $transformer->transform($propertyAccessor->getValue($mappingAwareSourceObject, $sourcePropertyName), $targetMappingOptions['options'], $targetObject, $mappingAwareSourceObject);
                    } else {
                        $value = $propertyAccessor->getValue($mappingAwareSourceObject, $sourcePropertyName);
                    }

                    $propertyAccessor->setValue($targetObject, $targetPropertyPath, $value);
                    ++$modificationCount;

                    $this->mappingLogger->info('Mapping property into target object', [
                        'targetObject' => $targetObject::class,
                        'targetPropertyPath' => $targetPropertyPath,
                        'value' => $value,
                        'withTransform' => (isset($targetMappingOptions['transformer'], $transformer)) ? $transformer::class : false,
                    ]);
                }
            } else {
                $this->mappingLogger->alert('try to access not writable property in target object : ' . $targetObject::class, [
                    'targetMappingOptions' => $targetMappingOptions,
                    'sourcePropertyName' => $sourcePropertyName,
                ]);
            }
        }

        if ($modificationCount > 0 && $flush) {
            $this->entityManager->flush();
        }

        return $targetObject;
    }

    /**
     * @throws ReflectionException
     * @throws MappingException
     */
    public function mapFromTarget(object $sourceObject, object $mappingAwareTargetObject): object
    {
        $mapping = $this->getPropertiesToMap($mappingAwareTargetObject);

        $propertyAccessor = new PropertyAccessor();

        foreach ($mapping['properties'] as $sourcePropertyName => $targetMappingOptions) {
            $targetPropertyPath = $targetMappingOptions['target'];

            if ($propertyAccessor->isWritable($mappingAwareTargetObject, $sourcePropertyName)) {
                if ($propertyAccessor->isReadable($sourceObject, $targetPropertyPath)) {
                    if (isset($targetMappingOptions['transformer'])) {
                        $reverseTransformer = $this->transformationLocator->returnTransformer($targetMappingOptions['transformer']);
                        $value = $reverseTransformer->reverseTransform($propertyAccessor->getValue($sourceObject, $sourcePropertyName), $targetMappingOptions['options'], $sourceObject, $mappingAwareTargetObject);
                    } else {
                        $value = $propertyAccessor->getValue($sourceObject, $targetPropertyPath);
                    }

                    $propertyAccessor->setValue($mappingAwareTargetObject, $sourcePropertyName, $value);

                    $this->mappingLogger->info('Mapping property into target Object', [
                        'targetObject' => $mappingAwareTargetObject::class,
                        'targetPropertyPath' => $targetPropertyPath,
                        'value' => $value,
                        'withReverseTransform' => (isset($targetMappingOptions['transformer'], $reverseTransformer)) ? $reverseTransformer::class : false,
                    ]);
                }
            } else {
                $this->mappingLogger->alert('try to access not writable property in target Object : ' . $mappingAwareTargetObject::class, [
                    'targetPropertyPath' => $targetPropertyPath,
                    'sourcePropertyName' => $sourcePropertyName,
                ]);
            }
        }

        return $mappingAwareTargetObject;
    }

    /**
     * @return array<mixed>
     *
     * @throws NotMappableObject
     * @throws ReflectionException
     */
    public function getPropertiesToMap(object $mappedObject): array
    {
        $reflection = new ReflectionClass($mappedObject::class);
        $attributesClass = $reflection->getAttributes(MappingAware::class);

        if (0 === count($attributesClass)) {
            throw new NotMappableObject('Can not automap object, because object is not using Attribute : ' . MappingAware::class);
        }

        $mapping = [];
        $properties = $reflection->getProperties();

        foreach ($attributesClass as $attributeClass) {
            $targetClass = $attributeClass->newInstance()->target;
            $mapping['targetClass'] = [];

            foreach ($properties as $property) {
                $attributesToMap = $property->getAttributes(MappingAware::class);
                foreach ($attributesToMap as $attributeToMap) {
                    $targetPath = $attributeToMap->newInstance()->target ?? $property->getName();
                    $mapping['targetClass'] = $targetClass;
                    $mapping['properties'][$property->getName()]['target'] = $targetPath;

                    if (null !== $attributeToMap->newInstance()->transformer) {
                        $mapping['properties'][$property->getName()]['transformer'] = $attributeToMap->newInstance()->transformer;
                        $mapping['properties'][$property->getName()]['options'] = $attributeToMap->newInstance()->options;
                    }
                }
            }
        }

        if (null === $mapping['targetClass']) {
            throw new NotMappableObject('Can not automap object, because target class is not specified on class Attribute : ' . MappingAware::class);
        }

        $this->mappingLogger->info('Properties to map', [$mapping]);

        return $mapping;
    }
}
