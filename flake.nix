# Copyright (c) anno Domini nostri Jesu Christi MMXXV John Boehr & contributors
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU Affero General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU Affero General Public License for more details.
#
# You should have received a copy of the GNU Affero General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.
{
  description = "jbboehr/phpstan-array-merge";

  inputs = {
    nixpkgs.url = "github:nixos/nixpkgs/nixos-26.05";
    nixpkgs-php81.url = "github:nixos/nixpkgs/nixos-25.05";
    systems.url = "github:nix-systems/default";

    git-hooks = {
      url = "github:cachix/git-hooks.nix";
      inputs.nixpkgs.follows = "nixpkgs";
    };
  };

  outputs =
    {
      self,
      nixpkgs,
      nixpkgs-php81,
      systems,
      git-hooks,
    }:
    let
      forEachSystem = nixpkgs.lib.genAttrs (import systems);
    in
    {
      checks = forEachSystem (system: {
        pre-commit-check = git-hooks.lib.${system}.run {
          src = ./.;
          hooks = {
            actionlint.enable = true;
            nixfmt.enable = true;
            shellcheck.enable = true;
          };
        };
      });

      devShells = forEachSystem (
        system:
        let
          pkgs = nixpkgs.legacyPackages.${system};
          legacyPkgs = nixpkgs-php81.legacyPackages.${system};
          pre-commit-check = self.checks.${system}.pre-commit-check;

          buildEnv =
            {
              php,
              withPcov ? true,
            }:
            php.buildEnv {
              extraConfig = "memory_limit = 2G";
              extensions =
                {
                  enabled,
                  all,
                }:
                enabled ++ (pkgs.lib.optionals withPcov [ all.pcov ]);
            };

          makeShell =
            {
              php,
              withPcov ? true,
            }:
            let
              php' = buildEnv { inherit php withPcov; };
            in
            pkgs.mkShell {
              packages = pre-commit-check.enabledPackages ++ [
                pkgs.mdl
                php'
                php'.packages.composer
              ];
              shellHook = ''
                ${pre-commit-check.shellHook}
                export PATH="$PWD/vendor/bin:$PATH"
                export PHPUNIT_WITH_PCOV="php -d memory_limit=512M -d pcov.directory=$PWD -d pcov.exclude=~vendor~ ./vendor/bin/phpunit"
              '';
            };
        in
        rec {
          php81 = makeShell { php = legacyPkgs.php81; };
          php82 = makeShell { php = pkgs.php82; };
          php83 = makeShell { php = pkgs.php83; };
          php84 = makeShell { php = pkgs.php84; };
          php85 = makeShell { php = pkgs.php85; };
          default = php85;
        }
      );

      formatter = forEachSystem (system: nixpkgs.legacyPackages.${system}.nixfmt);
    };
}
