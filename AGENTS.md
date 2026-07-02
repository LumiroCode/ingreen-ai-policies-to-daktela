Be smart about what you do. Notice small things and interdependencies. Think about the consequences of your actions in scope of current and future state of the project.

Keep code split by responsibilities. Clear-cut the interfaces between parts or modules and make them explicit. Prefer modularity and maintainability. Avoid code duplications. Abstract away the details. Use layers of logic. Make data flow in one direction always. Follow each: SOLID, CQRS, DRY, KISS and YAGNI. Prefere separate methods instead of overloading with flags. Think, think, think. Refactor often. Foster single source of truth for operations, switches, and data in code.

**Edit once at logic source / entry point, not multiple times on logic branches.**

Be surgically precise and make only minimal changes needed to fulfil the goal and instructions.

Work in an incremental manner - proof of concept (absolutely minimal code) > minimal value product (only one, singular base value we want to take from a feature, little code) > working feature > refactored code > optimised code. Always keep the code in a working state.

Perform self-review of the changes you make. Be your own critic. Ask yourself: "Does this code foster rules imposed in instructions? Does it make it easy to make changes in behaviour with small edits?".
