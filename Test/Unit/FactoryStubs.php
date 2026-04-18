<?php
/**
 * Copyright © Shubo. All rights reserved.
 * See LICENSE file for license details.
 *
 * Minimal factory stubs loaded before PHPUnit runs.
 *
 * Magento generates these factories at runtime from
 * `xsi:type="object"` arguments in di.xml. In a standalone unit-test
 * environment the code generator is not active, so PHPUnit's reflection
 * mock builder cannot synthesize mocks against class names that resolve
 * to nothing. Declaring paper-thin stubs here keeps the tests fast while
 * still allowing the SUT to depend on the real class names.
 *
 * The stubs are namespaced via `namespace ... { }` bracket syntax so a
 * single file can declare multiple namespaces without polluting the
 * global root namespace.
 */

declare(strict_types=1);

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses
// phpcs:disable PSR1.Files.SideEffects
// phpcs:disable Squiz.Classes.ClassFileName
// phpcs:disable Magento2.NamingConvention.ReservedWords.ForbiddenAsNameSpace
namespace Magento\Quote\Model\Quote\Address\RateResult {
    if (!\class_exists(ErrorFactory::class, false)) {
        class ErrorFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
    if (!\class_exists(MethodFactory::class, false)) {
        class MethodFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
}

namespace Magento\Shipping\Model\Rate {
    if (!\class_exists(ResultFactory::class, false)) {
        class ResultFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
}

namespace Shubo\ShippingCore\Model\ResourceModel\Shipment {
    if (!\class_exists(CollectionFactory::class, false)) {
        class CollectionFactory
        {
            /**
             * @param array<string, mixed> $data
             */
            public function create(array $data = []): object
            {
                return new \stdClass();
            }
        }
    }
}
// phpcs:enable
