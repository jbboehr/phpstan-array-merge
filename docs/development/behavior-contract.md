# Array merge behavior contract

These tests describe expected behavior independently of the implementation.
They provide a baseline for reviewing changes to inference, especially when a
precision improvement could exclude a real PHP outcome.

## Expected behaviors

| Area | Expected behavior | Examples and checks |
| --- | --- | --- |
| Empty operands | Empty operands contribute no entries. A single operand still undergoes numeric reindexing. | Empty arrays in either position and in three-operand merges. |
| String keys | The last value wins; the key retains its first insertion position. | Repeated keys, opposite orders, case-sensitive and empty string keys. |
| Integer keys | Values append in iteration order, with consecutive keys starting at zero. | Negative keys, gaps, repeated numeric keys, and mixed string/integer arrays. |
| Numeric strings | Canonical integer strings follow PHP's integer-key behavior; other strings retain their identity. | `'7'` versus `'07'`, `'+7'`, `'7.0'`, `'7e0'`, and `'-0'`. |
| Values | Merging is shallow. Null, false, and empty arrays are real replacement values. | Nested arrays are replaced; their contents are not recursively merged. |
| Optional fields | Both presence and absence are possible. An optional later write preserves the earlier value alternative. | Every subset of the small optional shapes is enumerated. |
| Operand unions | Every reachable alternative contributes possible outcomes, including benevolent unions. | Empty alternatives, conflicting key orders, and mixed shape/generic alternatives. |
| Nonemptiness | Any operand that excludes `[]` guarantees a nonempty merge. Otherwise an empty outcome must remain possible. | Guarantees are explicit in the operand catalogue, rather than inferred from generic samples. |
| Lists | Integer-only operands produce lists after reindexing. A claimed list must agree with each native outcome. | Generic integer arrays, nonempty lists, sparse shapes, and mixed-key controls. |
| Derived types | Keys, values, flipped arrays, sizes, and offset queries must admit the corresponding runtime outcomes. | Duplicate flipped values (including `7` versus `'7'`), optional offsets, overwrites, and count bounds. |
| Invalid operands | An operand must definitely be an array. Invalid operands produce `ErrorType`. | Scalars, mixed, objects, iterables, nullable arrays, and non-array union branches. |
| Impossible operands | A standalone impossible operand makes the merge impossible; an impossible union alternative contributes nothing. | `never`, required `never` fields, optional impossible fields, and nested impossible arrays. Invalid operands still take precedence. |
| Nested merges | Nested operands preserve order and overwrite semantics. A merge stored inside an array element remains inside that element. | Nested siblings with conflicting writes and a nested payload. |
| Parsing context | Nested type expressions retain their meaning and imported class names resolve in the supplied scope. | Callable/container unions and a namespaced class alias. |
| Serialization | Printing and reparsing retain the semantic result, including after an inference preview. | Each named result/error case is checked before and after a PHPDoc round-trip. |
| Template lifecycle | Previewing a partial specialization does not freeze bounds, lose unresolved templates, or mutate the original. | Both substitution orders, with a round-trip between substitutions and rightmost overwrite checks. |

## Test structure and independent expectations

- `tests/ArrayMergeContractTest.php` contains readable input/expected-type pairs
  and template state sequences. Expected types are written by hand and compared
  semantically, including key and value order; they are not generated snapshots.
  List cases assert list/key/value/nonempty semantics directly, since older
  PHPStan parsers can erase a `list<T>` annotation in the expected type.
- `tests/ArrayMergeNativeBehaviorTest.php` crosses all 17 declared operand kinds
  in both positions and five kinds in all three positions: 414 combinations and
  1,881 concrete native outcomes. Native `array_merge`, `array_keys`,
  `array_values`, and `array_flip` provide the oracle. PHPStan's
  `ConstantTypeHelper` translates concrete values into assertion types.
- Each runtime sample must first belong to its declared operand type. This
  catches invalid fixtures before they can be mistaken for inference defects.
- Each combination is a named PHPUnit dataset. Failure messages identify the
  operation, declared merge, inferred type, and excluded native outcome.

The matrix exhausts the branches of its finite shapes/unions. Generic arrays
have deliberately chosen samples covering empty, overlapping, fresh, reversed,
and sparse keys; they are not exhaustively enumerated. The existing specialized
tests continue to cover large shapes, unsealed arrays, recursive template graphs,
and traversal internals.

Exact precision is required for the explicit contract cases. For uncertain
shape combinations the matrix permits conservative widening, while requiring
sound outcomes, nonemptiness guarantees, and integer-only list inference. It
does not mandate one internal type representation for those combinations.

## Running and reviewing

```sh
composer phpunit -- --no-coverage --filter 'ArrayMergeContractTest|ArrayMergeNativeBehaviorTest'
composer phpunit -- --no-coverage
composer phpstan -- clear-result-cache
composer phpstan -- analyze --no-progress
composer phpcs
```

Use PHPUnit's `--filter` with a dataset name to replay one combination. CI also
runs these tests in the existing PHPStan compatibility jobs.

Clear PHPStan's result cache when checking changes to the extension. Cached
results can hide new diagnostics in fixtures whose source has not changed.

When a test fails, check fixture validity and the independent expected behavior
before changing either production code or an assertion. Keep a minimal named
case for a demonstrated defect. Do not replace expectations with the current
inferred output simply to make the suite pass.

For a passing addition, identify a realistic broken behavior it rejects. Examples
include reversing operands, retaining numeric keys, declaring every result
nonempty, erasing the result to a broad array, flattening an element's nested
merge, or freezing a template after its first preview.
